<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Events\ReferralChanged;
use Illuminate\Support\Facades\DB;

/**
 * Long-term (Future) leads are never lost: the receiver gets a monthly prompt until the lead is upgraded, closed or
 * lost; each "still nurturing" check-in extends the fee protection period; and the lead becomes hot automatically
 * when the receiver or the client says they're ready.
 */
final class Nurture
{
    public const PROMPT_DAYS = 30;

    public function __construct(private readonly Notifier $notifier) {}

    /** Temperature from the form's timeline answer: first option hot, second warm, any later one future. */
    public static function classify(Referral $ref): ?string
    {
        $form = app(IndustryTemplates::class)->resolve('form', $ref->group->industry, $ref->group_id)['body'];
        $field = collect($form)->firstWhere('role', 'timeline');
        $answer = $field ? data_get($ref->answers, $field['key']) : null;
        if (! $field || ! is_string($answer)) {
            return null;
        }
        $i = array_search($answer, (array) ($field['options'] ?? []), true);

        return match (true) {
            $i === 0 => 'hot',
            $i === 1 => 'warm',
            $i === false => null,
            default => 'future',
        };
    }

    public function onAccepted(Referral $ref): void
    {
        $months = (int) data_get($ref->group->rules, 'protection_months', 12);
        $ref->forceFill(['protection_until' => now()->addMonths($months)->toDateString(),
            'nurture_next_at' => $ref->temperature === 'future' ? now()->addDays(self::PROMPT_DAYS)->toDateString() : null])->save();
    }

    /** Daily: prompt receivers about Future leads that are due a check-in. */
    public function prompts(): void
    {
        $due = Referral::query()->with(['receiver.leader', 'group.industry'])->where(['temperature' => 'future', 'status' => 'accepted'])->whereNull('closed_at')
            ->whereNotNull('nurture_next_at')->where('nurture_next_at', '<=', now()->toDateString())->get();
        foreach ($due as $ref) {
            $this->touch($ref, 'prompt', 'system');
            $ref->forceFill(['nurture_next_at' => now()->addDays(self::PROMPT_DAYS)->toDateString()])->save();
            if ($ref->receiver !== null) {
                $this->notifier->send($ref->receiver->leader, 'referral.nurture', ['headline' => "Monthly check-in: {$ref->client_name}", 'body' => 'Still planning, ready now, or no longer going ahead? One tap keeps the referring team informed and your protection period running.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
            }
        }
    }

    public const CLIENT_TOUCH_DAYS = 60;

    /**
     * Daily: a gentle email to clients planning ahead, every 60 days, while the lead is Future and still open.
     * Only with an email address and confirmed consent; never after they stop these emails.
     */
    public function clientTouchpoints(): void
    {
        $refs = Referral::query()->with('receiver')->where(['temperature' => 'future', 'status' => 'accepted'])->whereNull('closed_at')
            ->whereNotNull('client_email')->whereNotNull('client_confirmed_at')->whereNull('client_emails_stopped_at')->get();
        foreach ($refs as $ref) {
            $last = DB::table('nurture_touchpoints')->where(['referral_id' => $ref->id, 'kind' => 'client_touch'])->max('created_at') ?? $ref->accepted_at;
            if ($last === null || now()->parse((string) $last)->gt(now()->subDays(self::CLIENT_TOUCH_DAYS))) {
                continue;
            }
            $this->touch($ref, 'client_touch', 'system');
            \Illuminate\Support\Facades\Notification::route('mail', $ref->client_email)->notify(new ClientNurtureEmail(
                (string) (strtok($ref->client_name, ' ') ?: 'there'), (string) $ref->receiver?->name, url('/r/'.$ref->client_token),
                \Illuminate\Support\Facades\URL::signedRoute('referrals.client.stop-emails', ['token' => $ref->client_token])));
        }
    }

    /** Weekly (Mondays): referring teams get a short summary of their Future referrals (no client contact details). */
    public function senderDigest(): void
    {
        $byTeam = Referral::query()->with(['sender.leader', 'receiver'])->where(['temperature' => 'future', 'status' => 'accepted'])->whereNull('closed_at')->get()->groupBy('sender_team_id');
        foreach ($byTeam as $refs) {
            $leader = $refs->first()?->sender->leader;
            if ($leader === null) {
                continue;
            }
            $lines = $refs->take(10)->map(function (Referral $r) {
                $last = DB::table('nurture_touchpoints')->where('referral_id', $r->id)->whereNotIn('kind', ['prompt', 'client_touch'])->orderByDesc('id')->value('kind');

                return $r->shortClientName().' with '.$r->receiver?->name.': '.match ($last) {
                    'still' => 'still nurturing', 'client_later' => 'client still planning', 'client_not_needed' => 'client may not go ahead', default => 'waiting for the first check-in',
                };
            })->implode('; ');
            $this->notifier->send($leader, 'referral.nurture', ['headline' => 'Your Future referrals this week ('.$refs->count().')', 'body' => $lines.'.'], url('/app/nurture'));
        }
    }

    public function stopClientEmails(Referral $ref): void
    {
        $ref->forceFill(['client_emails_stopped_at' => $ref->client_emails_stopped_at ?? now()])->save();
    }

    /** The receiver's check-in: still nurturing (optionally choosing the next date), ready now, or lost. */
    public function checkIn(Referral $ref, User $user, string $outcome, ?string $note, ?string $nextOn): void
    {
        abort_unless(Team::of($user)?->id === $ref->receiver_team_id && $ref->status === 'accepted' && $ref->closed_at === null, 403);
        match ($outcome) {
            'still' => (function () use ($ref, $user, $note, $nextOn) {
                $months = (int) data_get($ref->group->rules, 'protection_months', 12);
                // Active nurturing extends protection: never shortened, always at least the period from today.
                $until = max((string) $ref->protection_until, now()->addMonths($months)->toDateString());
                $ref->forceFill(['nurture_next_at' => $nextOn ?? now()->addDays(self::PROMPT_DAYS)->toDateString(), 'protection_until' => $until])->save();
                $this->touch($ref, 'still', 'pro', $note, $user);
            })(),
            'ready' => $this->upgrade($ref, 'pro', $note, $user),
            'lost' => (function () use ($ref, $user, $note) {
                $this->touch($ref, 'lost', 'pro', $note, $user);
                $lost = collect(app(Pipeline::class)->stages($ref))->firstWhere('kind', 'lost');
                abort_if($lost === null, 422, 'This network’s pipeline has no Lost stage.');
                app(Pipeline::class)->move($ref, $user, (string) $lost['key'], null, ['lost_reason' => $note ?: 'No longer going ahead']);
                $ref->forceFill(['nurture_next_at' => null])->save();
            })(),
            default => abort(422),
        };
        ReferralChanged::dispatch($ref, 'nurture');
    }

    /** The client's own check-in from their portal. */
    public function clientCheckIn(Referral $ref, string $answer): void
    {
        abort_unless($ref->status === 'accepted' && $ref->closed_at === null, 422);
        match ($answer) {
            'ready' => $this->upgrade($ref, 'client'),
            'later' => $this->touch($ref, 'client_later', 'client'),
            'not_needed' => (function () use ($ref) {
                $this->touch($ref, 'client_not_needed', 'client');
                $this->tellReceiver($ref, "{$ref->client_name} says they no longer need help", 'They told us from their page. You can mark the referral Lost, or reach out to check.');
            })(),
            default => abort(422),
        };
        ReferralChanged::dispatch($ref, 'nurture');
    }

    /** Future → hot: the monthly prompts stop and both teams hear about it. */
    private function upgrade(Referral $ref, string $source, ?string $note = null, ?User $user = null): void
    {
        $was = $ref->temperature;
        $ref->forceFill(['temperature' => 'hot', 'nurture_next_at' => null])->save();
        $this->touch($ref, $source === 'client' ? 'client_ready' : 'ready', $source, $note, $user);
        $who = $source === 'client' ? "{$ref->client_name} says they’re ready now" : "{$ref->client_name} is ready now";
        $this->tellReceiver($ref, $who, $source === 'client' ? 'They told us from their page. Reach out today.' : 'Marked ready by the receiving team.');
        if ($was === 'future') {
            $ref->loadMissing('sender.leader');
            $this->notifier->send($ref->sender->leader, 'referral.nurture', ['headline' => "Your Future referral is ready: {$ref->client_name}", 'body' => 'The lead moved from Future to Hot. Track it on the referral page.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    private function tellReceiver(Referral $ref, string $headline, string $body): void
    {
        $ref->loadMissing(['receiver.leader', 'group.industry']);
        if ($ref->receiver !== null) {
            $this->notifier->send($ref->receiver->leader, 'referral.nurture', ['headline' => $headline, 'body' => $body], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    private function touch(Referral $ref, string $kind, string $source, ?string $note = null, ?User $user = null): void
    {
        DB::table('nurture_touchpoints')->insert(['referral_id' => $ref->id, 'kind' => $kind, 'source' => $source, 'note' => $note, 'user_id' => $user?->id, 'created_at' => now()]);
    }
}
