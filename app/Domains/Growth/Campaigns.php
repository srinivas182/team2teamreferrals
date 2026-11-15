<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Support\TimeZones;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Drip campaigns to prospects (Sprint 46b).
 *
 * - People join a campaign by hand (from the prospect list), by matching its audience, or, for the ready-made
 *   "finish joining" sequence, by signing up without applying for a seat. An hour after they stop, the first email goes.
 * - Each step waits its hours, then sends inside the campaign's window in the person's own time zone, under the
 *   campaign's daily cap.
 * - Someone leaves as soon as they reach the goal (applied or joined), reply, unsubscribe, bounce or complain.
 * - A step with two subject lines splits its sends; once each side has 50 sends at least two days old, the one with
 *   more clicks wins and is used from then on.
 * - Nothing is sent while prospect email is switched off; people simply wait.
 */
final class Campaigns
{
    public const AB_MIN_SENDS = 50;

    public const AB_MIN_AGE_HOURS = 48;

    public const RUN_BATCH = 500;

    public static function enroll(Campaign $c, Prospect $p, ?User $actor = null): bool
    {
        $first = $c->steps()->first();
        if ($first === null || $c->status === 'draft' || $p->unsubscribed_at !== null || Prospects::suppressed($p->email) || self::reachedGoal($c, $p)
            || DB::table('campaign_enrollments')->where(['campaign_id' => $c->id, 'prospect_id' => $p->id])->exists()) {
            return false;
        }
        DB::table('campaign_enrollments')->insert(['campaign_id' => $c->id, 'prospect_id' => $p->id, 'status' => 'active', 'next_position' => 1,
            'next_send_at' => now()->addHours($first->wait_hours), 'enrolled_at' => now()]);
        Prospects::log($p, $actor, 'campaign', "Added to the campaign “{$c->name}”.");

        return true;
    }

    /** Signed up: into every active "finish joining" sequence. */
    public static function onSignup(User $user): void
    {
        $p = Prospect::query()->where('user_id', $user->id)->first();
        if ($p === null) {
            return;
        }
        foreach (Campaign::query()->where(['kind' => 'abandoned', 'status' => 'active'])->get() as $c) {
            self::enroll($c, $p);
        }
    }

    /** Every 5 minutes: enrol whoever now matches, then send what's due. */
    public static function run(): int
    {
        self::autoEnroll();
        if (! GrowthMailer::sendingOn()) {
            return 0;
        }
        self::decideTests();
        $sent = 0;
        $due = DB::table('campaign_enrollments as e')->join('campaigns as c', 'c.id', '=', 'e.campaign_id')->where(['e.status' => 'active', 'c.status' => 'active'])
            ->where('e.next_send_at', '<=', now())->orderBy('e.next_send_at')->limit(self::RUN_BATCH)->get(['e.*']);
        $campaigns = Campaign::query()->with('steps')->whereIn('id', $due->pluck('campaign_id')->unique())->get()->keyBy('id');
        foreach ($due as $e) {
            $c = $campaigns->get($e->campaign_id);
            $sent += $c !== null && self::advance($c, $e) ? 1 : 0;
        }

        return $sent;
    }

    /** The prospect replied to a campaign email (reply+g<send>@…): recorded, they leave the campaign, and their owner hears. */
    public static function reply(string $sendUlid, string $from, string $text): string
    {
        $send = DB::table('campaign_sends')->where('ulid', $sendUlid)->first();
        $p = $send !== null ? Prospect::query()->with('owner')->find((int) $send->prospect_id) : null;
        if ($send === null || $p === null || mb_strtolower(trim($from)) !== $p->email) {
            return 'unknown';
        }
        DB::table('campaign_sends')->where('id', $send->id)->update(['replied_at' => now(), 'updated_at' => now()]);
        Prospects::log($p, null, 'reply', 'Replied to “'.$send->subject.'”: '.mb_substr(trim($text), 0, 4000));
        self::exitAll($p, 'replied');
        Prospects::rescore($p);
        $tell = $p->owner !== null ? collect([$p->owner]) : User::permission('growth.manage')->where('is_active', true)->get();
        foreach ($tell as $u) {
            app(Notifier::class)->send($u, 'growth.tasks', ['headline' => "{$p->name()} replied", 'body' => mb_substr(trim($text), 0, 500)], Prospects::url($p));
        }

        return 'growth-reply';
    }

    /** A bounce or spam complaint from the email provider: never email them again. */
    public static function bounced(string $email, string $kind): void
    {
        $email = mb_strtolower(trim($email));
        Prospects::suppress($email, $kind === 'complaint' ? 'complained' : 'bounced');
        $p = Prospect::query()->where('email', $email)->first();
        if ($p === null) {
            return;
        }
        $last = DB::table('campaign_sends')->where('prospect_id', $p->id)->where('status', 'sent')->orderByDesc('id')->value('id');
        if ($last !== null) {
            DB::table('campaign_sends')->where('id', $last)->update(['bounced_at' => now(), 'updated_at' => now()]);
        }
        Prospects::log($p, null, 'email', $kind === 'complaint' ? 'Marked an email as spam: no more email to them.' : 'Their address bounced: no more email to them.');
        self::exitAll($p, $kind === 'complaint' ? 'complained' : 'bounced');
    }

    public static function unsubscribed(Prospect $p): void
    {
        $last = DB::table('campaign_sends')->where('prospect_id', $p->id)->where('status', 'sent')->orderByDesc('id')->value('id');
        if ($last !== null) {
            DB::table('campaign_sends')->where('id', $last)->update(['unsubscribed_at' => now(), 'updated_at' => now()]);
        }
        self::exitAll($p, 'unsubscribed');
    }

    /** A test of one step to the person setting it up (shown as [Test], not tracked, not counted). */
    public static function test(CampaignStep $step, User $to, ?string $variant = null): void
    {
        $company = \App\Domains\Content\Tokens::company();
        if (trim($company['postal_address']) === '') {
            throw ValidationException::withMessages(['test' => 'Add the company’s postal address in Admin → System → General first: every prospect email shows it.']);
        }
        $subject = $variant === 'b' && $step->subject_b !== null ? $step->subject_b : $step->subject;
        $fill = fn (string $s) => strtr($s, ['{first_name}' => $to->first_name, '{city}' => 'Austin']);
        (new AnonymousNotifiable)->route('mail', $to->email)->notify(new GrowthMessage('[Test] '.$fill($subject), $fill($step->body), $step->cta_label, self::link($step), url('/growth/unsubscribe/test')));
    }

    /**
     * Per campaign: people, sends and what happened, and what it led to.
     *
     * @return array<string, int|float>
     */
    public static function stats(Campaign $c): array
    {
        $e = DB::table('campaign_enrollments')->where('campaign_id', $c->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $s = DB::table('campaign_sends')->where(['campaign_id' => $c->id, 'status' => 'sent'])
            ->selectRaw('count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked, count(replied_at) as replied, count(unsubscribed_at) as unsubscribed, count(bounced_at) as bounced')->first();
        $converted = DB::table('campaign_enrollments')->where(['campaign_id' => $c->id, 'status' => 'converted'])->pluck('prospect_id');
        $users = DB::table('prospects')->whereIn('id', $converted)->whereNotNull('user_id')->pluck('user_id');
        $teams = DB::table('teams')->whereIn('leader_id', $users)->pluck('id');
        $revenue = (int) DB::table('invoices')->where(['owner_type' => 'team', 'status' => 'paid'])->whereIn('owner_id', $teams)->sum('total_cents');

        return ['enrolled' => (int) $e->sum(), 'active' => (int) ($e['active'] ?? 0), 'converted' => (int) ($e['converted'] ?? 0), 'completed' => (int) ($e['completed'] ?? 0), 'exited' => (int) ($e['exited'] ?? 0),
            'sent' => (int) ($s->sent ?? 0), 'opened' => (int) ($s->opened ?? 0), 'clicked' => (int) ($s->clicked ?? 0), 'replied' => (int) ($s->replied ?? 0),
            'unsubscribed' => (int) ($s->unsubscribed ?? 0), 'bounced' => (int) ($s->bounced ?? 0), 'revenue' => $revenue / 100];
    }

    /** @return list<array{step: int, variant: string, subject: string, sent: int, opened: int, clicked: int}> */
    public static function stepStats(Campaign $c): array
    {
        return array_values(DB::table('campaign_sends as s')->join('campaign_steps as st', 'st.id', '=', 's.campaign_step_id')->where(['s.campaign_id' => $c->id, 's.status' => 'sent'])
            ->groupBy('st.position', 's.variant', 's.subject')->orderBy('st.position')->orderBy('s.variant')
            ->selectRaw('st.position as step, s.variant, s.subject, count(*) as sent, count(s.opened_at) as opened, count(s.clicked_at) as clicked')->get()
            ->map(fn ($r) => ['step' => (int) $r->step, 'variant' => (string) $r->variant, 'subject' => (string) $r->subject, 'sent' => (int) $r->sent, 'opened' => (int) $r->opened, 'clicked' => (int) $r->clicked])->all());
    }

    public static function link(CampaignStep $step): ?string
    {
        if ($step->cta_url === null || $step->cta_url === '') {
            return null;
        }
        $url = str_starts_with($step->cta_url, '/') ? url($step->cta_url) : $step->cta_url;
        $step->loadMissing('campaign');
        $utm = http_build_query(['utm_source' => 't2t', 'utm_medium' => 'email', 'utm_campaign' => Str::slug($step->campaign->name)]);

        return $url.(str_contains($url, '?') ? '&' : '?').$utm;
    }

    /** One person's next email: check they still belong, wait for their window and the cap, send, schedule the next. */
    private static function advance(Campaign $c, stdClass $e): bool
    {
        $p = Prospect::query()->with(['city', 'owner'])->find((int) $e->prospect_id);
        if ($p === null) {
            return false;
        }
        if (self::reachedGoal($c, $p)) {
            self::end($e, 'converted', $c->goal);
            Prospects::log($p, null, 'campaign', "Reached the goal of “{$c->name}”.");

            return false;
        }
        if ($p->unsubscribed_at !== null || Prospects::suppressed($p->email)) {
            self::end($e, 'exited', 'unsubscribed');

            return false;
        }
        $local = now()->setTimezone(self::zone($p))->toImmutable();
        $next = self::windowStart($c, $local);
        if ($next !== null) {
            DB::table('campaign_enrollments')->where('id', $e->id)->update(['next_send_at' => $next->utc()]);

            return false;
        }
        if (DB::table('campaign_sends')->where(['campaign_id' => $c->id, 'status' => 'sent'])->where('sent_at', '>=', now()->startOfDay())->count() >= $c->daily_cap) {
            DB::table('campaign_enrollments')->where('id', $e->id)->update(['next_send_at' => now()->addDay()->startOfDay()->addHours(1)]);

            return false;
        }
        $step = $c->steps->firstWhere('position', (int) $e->next_position);
        if ($step === null) {
            self::end($e, 'completed', null);

            return false;
        }
        $variant = $step->subject_b !== null ? ($step->winner ?? (DB::table('campaign_sends')->where('campaign_step_id', $step->id)->count() % 2 === 0 ? 'a' : 'b')) : 'a';
        $subject = $variant === 'b' ? (string) $step->subject_b : $step->subject;
        $ulid = strtolower((string) Str::ulid());
        $sendId = DB::table('campaign_sends')->insertGetId(['ulid' => $ulid, 'campaign_id' => $c->id, 'campaign_step_id' => $step->id, 'prospect_id' => $p->id, 'variant' => $variant,
            'subject' => mb_substr(strtr($subject, ['{first_name}' => $p->first_name ?: 'there', '{city}' => $p->city->name ?? 'your city']), 0, 150), 'status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $ok = GrowthMailer::send($p, $subject, $step->body, $step->cta_label, self::link($step), 'campaign', ['campaign' => $c->ulid, 'step' => $step->position, 'variant' => $variant], $ulid);
        if (! $ok) {
            DB::table('campaign_sends')->where('id', $sendId)->update(['status' => 'skipped', 'sent_at' => null, 'skip_reason' => mb_substr((string) GrowthMailer::blocker($p), 0, 120)]);
        }
        $following = $c->steps->firstWhere('position', (int) $e->next_position + 1);
        DB::table('campaign_enrollments')->where('id', $e->id)->update($following !== null
            ? ['next_position' => (int) $e->next_position + 1, 'next_send_at' => now()->addHours($following->wait_hours)]
            : ['status' => 'completed', 'next_send_at' => null, 'ended_at' => now(), 'next_position' => (int) $e->next_position + 1]);

        return $ok;
    }

    /** Campaigns with an audience take in matching prospects; the "finish joining" sequence catches recent sign-ups. */
    private static function autoEnroll(): void
    {
        foreach (Campaign::query()->where('status', 'active')->get() as $c) {
            if ($c->kind === 'abandoned') {
                $q = Prospect::query()->where('stage', 'signed_up')->whereNotNull('user_id')->where('created_at', '>=', now()->subDays(7));
            } elseif (! empty($c->audience)) {
                $a = $c->audience;
                $q = Prospect::query()->whereNull('unsubscribed_at')
                    ->when($a['stage'] ?? null, fn ($w, $v) => $w->where('stage', $v))->when($a['source'] ?? null, fn ($w, $v) => $w->where('source', $v))
                    ->when($a['industry'] ?? null, fn ($w, $v) => $w->where('industry_id', (int) $v))->when($a['state'] ?? null, fn ($w, $v) => $w->where('state_code', strtoupper((string) $v)))
                    ->when($a['temp'] ?? null, fn ($w, $v) => match ($v) {
                        'hot' => $w->where('score', '>=', Prospects::HOT), 'warm' => $w->whereBetween('score', [Prospects::WARM, Prospects::HOT - 1]), default => $w->where('score', '<', Prospects::WARM),
                    });
            } else {
                continue;
            }
            foreach ($q->whereNotIn('id', DB::table('campaign_enrollments')->where('campaign_id', $c->id)->select('prospect_id'))->limit(1000)->get() as $p) {
                self::enroll($c, $p);
            }
        }
    }

    /** A/B: once both subject lines have enough sends old enough to judge, the one with more clicks (by rate) wins. */
    private static function decideTests(): void
    {
        foreach (CampaignStep::query()->whereNotNull('subject_b')->whereNull('winner')->get() as $step) {
            $r = DB::table('campaign_sends')->where(['campaign_step_id' => $step->id, 'status' => 'sent'])->where('sent_at', '<=', now()->subHours(self::AB_MIN_AGE_HOURS))
                ->selectRaw('variant, count(*) as n, count(clicked_at) as clicks')->groupBy('variant')->get()->keyBy('variant');
            $a = $r->get('a');
            $b = $r->get('b');
            if ($a === null || $b === null || $a->n < self::AB_MIN_SENDS || $b->n < self::AB_MIN_SENDS) {
                continue;
            }
            $step->forceFill(['winner' => $b->clicks / $b->n > $a->clicks / $a->n ? 'b' : 'a'])->save();
        }
    }

    private static function reachedGoal(Campaign $c, Prospect $p): bool
    {
        return Prospects::RANK[$p->stage] >= Prospects::RANK[$c->goal];
    }

    private static function exitAll(Prospect $p, string $reason): void
    {
        DB::table('campaign_enrollments')->where(['prospect_id' => $p->id, 'status' => 'active'])->update(['status' => 'exited', 'exit_reason' => $reason, 'next_send_at' => null, 'ended_at' => now()]);
    }

    private static function end(stdClass $e, string $status, ?string $reason): void
    {
        DB::table('campaign_enrollments')->where('id', $e->id)->update(['status' => $status, 'exit_reason' => $reason, 'next_send_at' => null, 'ended_at' => now()]);
    }

    /** Their own time zone: the account's, else their city's, else Central. */
    private static function zone(Prospect $p): string
    {
        if ($p->user_id !== null && ($tz = DB::table('users')->where('id', $p->user_id)->value('timezone')) !== null) {
            return (string) $tz;
        }
        if ($p->city !== null && $p->state_code !== null) {
            return TimeZones::forPlace($p->state_code, $p->city->lat !== null ? (float) $p->city->lat : null, $p->city->lon !== null ? (float) $p->city->lon : null);
        }

        return 'America/Chicago';
    }

    /** Null when now is inside the sending window; otherwise when the window next opens (local time). */
    private static function windowStart(Campaign $c, CarbonImmutable $local): ?CarbonImmutable
    {
        $inDay = ! $c->weekdays_only || ! $local->isWeekend();
        if ($inDay && $local->hour >= $c->window_start && $local->hour < $c->window_end) {
            return null;
        }
        $next = $local->hour < $c->window_start && $inDay ? $local->setTime($c->window_start, 0) : $local->addDay()->setTime($c->window_start, 0);
        while ($c->weekdays_only && $next->isWeekend()) {
            $next = $next->addDay();
        }

        return $next;
    }
}
