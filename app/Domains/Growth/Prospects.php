<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Territory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The prospect CRM (Sprint 46a): who might join, where they came from, how far they got, and the team's work on them.
 *
 * Prospects arrive by themselves (sign-ups, confirmed reservations, "Joining a network" messages) or are added by
 * hand or imported. Signing up, applying and joining move them along automatically. Staff see everyone; a group
 * owner sees the prospects for their own networks. A "forget me" deletes the prospect and keeps only a hash of the
 * address, so they are never added or emailed again.
 */
final class Prospects
{
    public const HOT = 30;

    public const WARM = 10;

    /** How far each stage is; automatic moves only go forward. */
    public const RANK = ['lost' => -1, 'new' => 0, 'contacted' => 1, 'interested' => 2, 'signed_up' => 3, 'applied' => 4, 'joined' => 5];

    public const IMPORT_MAX_ROWS = 5000;

    public const ALERT_GAP_DAYS = 7;

    /**
     * Who can see what: staff with growth.manage see everyone; a group owner sees their networks' prospects.
     *
     * @return Builder<Prospect>
     */
    public static function visibleTo(User $user, ?Group $group = null): Builder
    {
        $q = Prospect::query();
        if ($group !== null) {
            abort_unless(self::isStaff($user) || $group->isOwnedBy($user), 403);

            return $q->where(fn ($w) => $w->where('group_id', $group->id)->orWhere(fn ($i) => $i->whereNull('group_id')->where('industry_id', $group->industry_id)));
        }
        abort_unless(self::isStaff($user), 403);

        return $q;
    }

    public static function isStaff(User $user): bool
    {
        return $user->can('growth.manage');
    }

    /**
     * Add or update by email. An existing prospect keeps what it has: only empty fields are filled in.
     *
     * @param  array<string, mixed>  $data
     */
    public static function upsert(array $data, string $source, string $consentBasis, ?User $actor = null, ?string $detail = null): ?Prospect
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || self::suppressed($email, ['forgotten'])) {
            return null;
        }
        $p = Prospect::query()->where('email', $email)->first();
        $fields = array_intersect_key($data, array_flip(['first_name', 'last_name', 'business', 'phone', 'industry_id', 'group_id', 'city_id', 'state_code', 'tags']));
        if ($p === null) {
            $p = new Prospect(['email' => $email, 'source' => $source, 'source_detail' => $detail !== null ? mb_substr($detail, 0, 190) : null, 'consent_basis' => $consentBasis, 'created_by' => $actor?->id, 'stage' => 'new']);
            $p->fill(array_filter($fields, fn ($v) => $v !== null && $v !== ''));
            self::fillLocation($p);
            $p->last_activity_at = now();
            $p->save();
            self::log($p, $actor, 'created', 'Added from: '.(Prospect::SOURCES[$source] ?? $source).($detail ? " ({$detail})" : '').'.');
        } else {
            foreach ($fields as $k => $v) {
                if (($p->{$k} === null || $p->{$k} === '' || $p->{$k} === []) && $v !== null && $v !== '') {
                    $p->{$k} = $v;
                }
            }
            self::fillLocation($p);
            $p->save();
        }

        return $p;
    }

    /** Signed up: linked to the account and moved to "Signed up". */
    public static function onSignup(User $user): void
    {
        $inviter = DB::table('invite_signups as s')->join('teams as t', 't.id', '=', 's.inviter_team_id')->where('s.user_id', $user->id)->value('t.name');
        $p = self::upsert(['email' => $user->email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'phone' => $user->phone], 'signup', 'signed_up', null,
            $inviter !== null ? "invited by {$inviter}" : null);
        if ($p === null) {
            return;
        }
        if ($p->user_id === null && ! Prospect::query()->where('user_id', $user->id)->exists()) {
            $p->user_id = $user->id;
        }
        self::advance($p, 'signed_up', 'signup', 'Created an account.');
    }

    /** A seat event for a team: its people's prospects move to Applied or Joined. */
    public static function onSeatEvent(Seat $seat, ?Team $team, string $event): void
    {
        if ($event === 'open') {
            self::alertSeatOpen($seat);

            return;
        }
        $to = match ($event) {
            'applied', 'waitlisted' => 'applied',
            'claimed', 'backup' => 'joined',
            default => null,
        };
        if ($to === null || $team === null || $seat->group->is_sandbox) {
            return;
        }
        $users = DB::table('team_members')->where('team_id', $team->id)->pluck('user_id')->push($team->leader_id)->unique();
        foreach (Prospect::query()->whereIn('user_id', $users)->get() as $p) {
            if ($p->group_id === null) {
                $p->group_id = $seat->group_id;
            }
            self::advance($p, $to, $to, ($to === 'joined' ? 'Joined' : 'Applied for').' '.$seat->group->name.' in '.$seat->territory_name.'.');
        }
    }

    public static function onReservation(int $reservationId): void
    {
        $r = DB::table('reservations as r')->join('groups as g', 'g.id', '=', 'r.group_id')->join('cities as c', 'c.id', '=', 'r.city_id')->join('states as s', 's.id', '=', 'c.state_id')
            ->where('r.id', $reservationId)->first(['r.*', 'g.industry_id', 'g.name as group_name', 'c.name as city_name', 's.code as state_code']);
        if ($r === null) {
            return;
        }
        $parts = preg_split('/\s+/', trim((string) $r->name), 2) ?: [];
        $p = self::upsert(['email' => $r->email, 'first_name' => $parts[0] ?? null, 'last_name' => $parts[1] ?? null, 'business' => $r->business, 'group_id' => (int) $r->group_id,
            'industry_id' => (int) $r->industry_id, 'city_id' => (int) $r->city_id], 'reservation', 'reserved', null, "{$r->group_name}, {$r->city_name}");
        if ($p !== null) {
            self::advance($p, 'interested', 'reservation', "Reserved a seat in {$r->city_name}, {$r->state_code} ({$r->group_name}).");
        }
    }

    public static function onContactMessage(int $messageId): void
    {
        $m = DB::table('contact_messages')->where('id', $messageId)->first();
        if ($m === null || $m->topic !== 'joining') {
            return;
        }
        $parts = preg_split('/\s+/', trim((string) $m->name), 2) ?: [];
        $p = self::upsert(['email' => $m->email, 'first_name' => $parts[0] ?? null, 'last_name' => $parts[1] ?? null], 'contact_form', 'wrote_to_us', null, 'Contact page: joining');
        if ($p !== null) {
            self::log($p, null, 'note', 'Wrote through the Contact page: '.mb_substr((string) $m->message, 0, 2000));
            self::advance($p, 'interested', 'contact', null);
        }
    }

    public static function setStage(Prospect $p, string $stage, User $actor, ?string $lostReason = null): void
    {
        if (! isset(Prospect::STAGES[$stage])) {
            throw ValidationException::withMessages(['stage' => 'Unknown stage.']);
        }
        if ($stage === 'lost' && trim((string) $lostReason) === '') {
            throw ValidationException::withMessages(['lost_reason' => 'Say why, so the next person knows.']);
        }
        $from = $p->stage;
        $p->forceFill(['stage' => $stage, 'lost_reason' => $stage === 'lost' ? mb_substr(trim((string) $lostReason), 0, 255) : null, 'joined_at' => $stage === 'joined' ? ($p->joined_at ?? now()) : $p->joined_at, 'last_activity_at' => now()])->save();
        self::log($p, $actor, 'stage', Prospect::STAGES[$from].' → '.Prospect::STAGES[$stage].($stage === 'lost' ? ": {$lostReason}" : ''), ['from' => $from, 'to' => $stage]);
        self::rescore($p);
    }

    public static function assign(Prospect $p, ?User $owner, User $actor): void
    {
        $p->forceFill(['owner_id' => $owner?->id])->save();
        self::log($p, $actor, 'owner', $owner !== null ? "Owner: {$owner->name}" : 'Owner removed');
        if ($owner !== null && $owner->id !== $actor->id) {
            app(Notifier::class)->send($owner, 'growth.tasks', ['headline' => "{$actor->first_name} gave you a prospect: {$p->name()}", 'body' => 'It’s in your prospects now.'], self::url($p));
        }
    }

    public static function note(Prospect $p, User $actor, string $text): void
    {
        self::log($p, $actor, 'note', mb_substr(trim($text), 0, 5000));
        $p->forceFill(['last_activity_at' => now()])->save();
    }

    public static function addTask(Prospect $p, User $actor, User $assignee, string $title, string $dueAt): int
    {
        $id = (int) DB::table('prospect_tasks')->insertGetId(['prospect_id' => $p->id, 'assignee_id' => $assignee->id, 'title' => mb_substr(trim($title), 0, 190), 'due_at' => now()->parse($dueAt),
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        self::log($p, $actor, 'task', "Task for {$assignee->name}: {$title} (due ".now()->parse($dueAt)->toFormattedDateString().')');

        return $id;
    }

    public static function completeTask(int $taskId, User $actor): void
    {
        $t = DB::table('prospect_tasks')->where('id', $taskId)->first() ?? abort(404);
        DB::table('prospect_tasks')->where('id', $taskId)->update(['done_at' => $t->done_at === null ? now() : null, 'updated_at' => now()]);
        $p = Prospect::query()->findOrFail((int) $t->prospect_id);
        self::log($p, $actor, 'task', ($t->done_at === null ? 'Done: ' : 'Reopened: ').$t->title);
    }

    /**
     * Import rows already mapped to our fields. The importer confirms the list wasn't bought.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{added: int, updated: int, skipped: int, problems: list<string>}
     */
    public static function import(array $rows, User $actor, string $fileName, ?Group $group): array
    {
        if (count($rows) > self::IMPORT_MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Up to '.number_format(self::IMPORT_MAX_ROWS).' rows at a time, please.']);
        }
        $out = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'problems' => []];
        $cities = [];
        foreach ($rows as $i => $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $out['skipped']++;
                if (count($out['problems']) < 20) {
                    $out['problems'][] = 'Row '.($i + 2).': no valid email.';
                }

                continue;
            }
            $state = strtoupper(mb_substr(trim((string) ($row['state'] ?? '')), 0, 2));
            $cityName = trim((string) ($row['city'] ?? ''));
            $cityId = null;
            if ($cityName !== '') {
                $key = mb_strtolower($cityName).'|'.$state;
                $cities[$key] ??= DB::table('cities as c')->join('states as s', 's.id', '=', 'c.state_id')->whereRaw('lower(c.name) = ?', [mb_strtolower($cityName)])
                    ->when($state !== '', fn ($q) => $q->where('s.code', $state))->orderByDesc('c.population')->value('c.id');
                $cityId = $cities[$key];
            }
            $existed = Prospect::query()->where('email', $email)->exists();
            $p = self::upsert(['email' => $email, 'first_name' => $row['first_name'] ?? null, 'last_name' => $row['last_name'] ?? null, 'business' => $row['business'] ?? null,
                'phone' => $row['phone'] ?? null, 'city_id' => $cityId, 'state_code' => $state !== '' ? $state : null, 'group_id' => $group?->id, 'industry_id' => $group?->industry_id],
                'import', 'imported_not_purchased', $actor, $fileName);
            if ($p === null) {
                $out['skipped']++;

                continue;
            }
            $existed ? $out['updated']++ : $out['added']++;
        }
        activity('growth')->causedBy($actor)->event('prospects-imported')->withProperties([...array_diff_key($out, ['problems' => 1]), 'file' => $fileName, 'group' => $group?->slug])
            ->log("Imported prospects from {$fileName}");

        return $out;
    }

    /** "Forget me": delete everything about them and keep only a hash, so they're never added or emailed again. */
    public static function forget(Prospect $p, User $actor): void
    {
        self::suppress($p->email, 'forgotten');
        activity('growth')->causedBy($actor)->event('prospect-forgotten')->withProperties(['prospect' => $p->ulid])->log('A prospect was forgotten on request');
        $p->delete();
    }

    /** @param list<string>|null $reasons */
    public static function suppressed(string $email, ?array $reasons = null): bool
    {
        return DB::table('email_suppressions')->where('email_hash', hash('sha256', mb_strtolower(trim($email))))->when($reasons !== null, fn ($q) => $q->whereIn('reason', $reasons))->exists();
    }

    public static function suppress(string $email, string $reason): void
    {
        DB::table('email_suppressions')->insertOrIgnore(['email_hash' => hash('sha256', mb_strtolower(trim($email))), 'reason' => $reason, 'created_at' => now()]);
    }

    /**
     * Lead score: how far they got, plus recent engagement (90 days). Hot from 30, warm from 10.
     */
    public static function rescore(Prospect $p): int
    {
        $base = ['new' => 0, 'contacted' => 2, 'interested' => 8, 'signed_up' => 12, 'applied' => 25, 'joined' => 40, 'lost' => 0][$p->stage] ?? 0;
        $points = ['email_open' => 1, 'email_click' => 3, 'visit' => 2, 'reply' => 8];
        $recent = DB::table('prospect_activities')->where('prospect_id', $p->id)->whereIn('type', array_keys($points))->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $engagement = 0;
        foreach ($points as $type => $pt) {
            $engagement += min(10, (int) ($recent[$type] ?? 0)) * $pt; // each kind counts up to 10 times
        }
        $score = min(100, $base + $engagement);
        if ($score !== $p->score) {
            $p->forceFill(['score' => $score])->save();
        }

        return $score;
    }

    /** Daily: remind people of prospect tasks due today or overdue (one message each). */
    public static function remindTasks(): int
    {
        $due = DB::table('prospect_tasks as t')->join('prospects as p', 'p.id', '=', 't.prospect_id')->whereNull('t.done_at')->where('t.due_at', '<=', now()->endOfDay())
            ->where(fn ($q) => $q->whereNull('t.reminded_at')->orWhere('t.reminded_at', '<', now()->startOfDay()))->get(['t.id', 't.assignee_id', 't.title', 'p.first_name', 'p.last_name', 'p.email']);
        foreach ($due->groupBy('assignee_id') as $userId => $tasks) {
            $u = User::query()->find((int) $userId);
            if ($u === null || ! $u->is_active) {
                continue;
            }
            $list = $tasks->take(10)->map(fn ($t) => '• '.$t->title.' ('.(trim(($t->first_name ?? '').' '.($t->last_name ?? '')) ?: $t->email).')')->implode("\n");
            app(Notifier::class)->send($u, 'growth.tasks', ['headline' => $tasks->count() === 1 ? 'A prospect task is due' : "{$tasks->count()} prospect tasks are due", 'body' => $list], url('/app/prospects/tasks'));
            DB::table('prospect_tasks')->whereIn('id', $tasks->pluck('id'))->update(['reminded_at' => now()]);
        }

        return $due->count();
    }

    /** A seat opened and nobody was waiting: tell matching prospects in that city (at most once a week each). */
    public static function alertSeatOpen(Seat $seat): int
    {
        $group = $seat->group()->with('industry')->firstOrFail();
        if ($group->is_sandbox || $group->is_private) {
            return 0;
        }
        $sent = 0;
        $candidates = Prospect::query()->with('city')->whereNotNull('city_id')->whereNull('unsubscribed_at')->whereNotIn('stage', ['joined', 'lost'])
            ->where(fn ($q) => $q->where('group_id', $group->id)->orWhere(fn ($i) => $i->whereNull('group_id')->where('industry_id', $group->industry_id)))
            ->where(fn ($q) => $q->whereNull('alerted_at')->orWhere('alerted_at', '<', now()->subDays(self::ALERT_GAP_DAYS)))->limit(500)->get();
        foreach ($candidates as $p) {
            if ($p->city === null) {
                continue;
            }
            $t = Territory::fromCity($group->industry->territory_unit, $p->city);
            if ($t['territory_type'] !== $seat->territory_type || (int) $t['territory_id'] !== $seat->territory_id) {
                continue;
            }
            $url = url('/groups/'.$group->slug).'?utm_source=t2t&utm_medium=email&utm_campaign=seat-open';
            $ok = GrowthMailer::send($p, "A {$group->industry->name} seat just opened in {$seat->territory_name}",
                "Hi {first_name},\n\nThe {$group->name} seat for {$seat->territory_name} is open, and nobody is waiting for it. The team holding it receives the network's referrals for that area.\n\nIf you'd like it, apply now: the first months are free.",
                'See the seat', $url, 'alert');
            $p->forceFill(['alerted_at' => now()])->save();
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    public static function url(Prospect $p): string
    {
        return url('/app/prospects/'.$p->ulid);
    }

    /** @param array<string, mixed> $meta */
    public static function log(Prospect $p, ?User $actor, string $type, ?string $body, array $meta = []): void
    {
        DB::table('prospect_activities')->insert(['prospect_id' => $p->id, 'user_id' => $actor?->id, 'type' => $type, 'body' => $body, 'meta' => $meta === [] ? null : json_encode($meta), 'created_at' => now()]);
    }

    /** Move forward to a stage (never back), noting why. */
    private static function advance(Prospect $p, string $stage, string $type, ?string $text): void
    {
        if (self::RANK[$stage] > self::RANK[$p->stage]) {
            $p->stage = $stage;
            $p->lost_reason = null;
            if ($stage === 'joined') {
                $p->joined_at = now();
            }
        }
        $p->last_activity_at = now();
        $p->save();
        if ($text !== null) {
            self::log($p, null, $type, $text);
        }
        self::rescore($p);
    }

    private static function fillLocation(Prospect $p): void
    {
        if ($p->city_id !== null && $p->state_code === null) {
            $p->state_code = DB::table('cities as c')->join('states as s', 's.id', '=', 'c.state_id')->where('c.id', $p->city_id)->value('s.code');
        }
        if ($p->group_id !== null && $p->industry_id === null) {
            $p->industry_id = Group::query()->whereKey($p->group_id)->value('industry_id');
        }
    }
}
