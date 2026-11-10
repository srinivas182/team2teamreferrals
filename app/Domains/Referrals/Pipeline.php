<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Working an accepted referral: stages from the group's (or industry's) pipeline, "Log a call" for first contact,
 * progress notes the sender sees, Won/Lost with the details closing needs, deadline reminders and nudges.
 */
final class Pipeline
{
    public const NUDGE_EVERY_DAYS = 3;

    public function __construct(private readonly IndustryTemplates $templates, private readonly Notifier $notifier) {}

    /** @return list<array{key: string, label: string, kind: string, client_label?: ?string, limit_days?: ?int}> */
    public function stages(Referral $ref): array
    {
        /** @var list<array{key: string, label: string, kind: string, client_label?: ?string, limit_days?: ?int}> */
        return $this->templates->resolve('stages', $ref->group->industry, $ref->group_id)['body'];
    }

    /** @return ?array{key: string, label: string, kind: string, client_label?: ?string, limit_days?: ?int} */
    public function stage(Referral $ref, ?string $key = null): ?array
    {
        $key ??= $ref->stage;

        return collect($this->stages($ref))->firstWhere('key', $key);
    }

    /** Called when a referral is accepted: it lands in the first stage and the clocks start. */
    public function start(Referral $ref): void
    {
        $ref->forceFill(['stage' => $this->stages($ref)[0]['key'], 'stage_changed_at' => now()])->save();
    }

    /** One tap: first contact is made (stops the first-contact clock). */
    public function logCall(Referral $ref, User $user, ?string $note): void
    {
        $team = $this->mustWork($ref, $user);
        $first = $ref->first_contact_at === null;
        $ref->forceFill(['first_contact_at' => $ref->first_contact_at ?? now(), 'last_update_at' => now()])->save();
        $this->event($ref, 'call_logged', $team, $user, ['note' => $note]);
        // A brand-new referral moves to the first working stage after the first call.
        $stages = $this->stages($ref);
        if ($first && $ref->stage === $stages[0]['key'] && isset($stages[1]) && $stages[1]['kind'] === 'open') {
            $this->moveTo($ref, $team, $user, $stages[1]['key'], $note);

            return;
        }
        $this->tellSender($ref, $team, $note ?? 'They contacted your client.');
    }

    /** @param array{closing_date?: ?string, closing_value?: mixed, lost_reason?: ?string} $details */
    public function move(Referral $ref, User $user, string $key, ?string $note, array $details = []): void
    {
        $team = $this->mustWork($ref, $user);
        $stage = $this->stage($ref, $key) ?? throw ValidationException::withMessages(['stage' => 'That stage isn’t in this network’s pipeline.']);
        if ($stage['kind'] === 'won') {
            if (empty($details['closing_date']) || ! is_numeric($details['closing_value'] ?? null) || (float) $details['closing_value'] < 0) {
                throw ValidationException::withMessages(['closing_date' => 'Add the closing date and value: they’re needed to close the referral (and any fee).']);
            }
            $ref->forceFill(['closing_date' => $details['closing_date'], 'closing_value_cents' => (int) round((float) $details['closing_value'] * 100), 'closed_at' => now()]);
        }
        if ($stage['kind'] === 'lost') {
            if (trim((string) ($details['lost_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['lost_reason' => 'Tell the sender why it didn’t go ahead.']);
            }
            $ref->forceFill(['lost_reason' => mb_substr(trim((string) ($details['lost_reason'] ?? '')), 0, 500), 'closed_at' => now()]);
        }
        if ($stage['kind'] === 'open') {
            $ref->forceFill(['closed_at' => null, 'closing_date' => null, 'closing_value_cents' => null, 'lost_reason' => null]);
        }
        $wasWon = $ref->close_status !== null;
        $this->moveTo($ref, $team, $user, $key, $note);
        // A Won close asks the sender to confirm; moving back to an open stage reopens it.
        if ($stage['kind'] === 'won') {
            app(Closing::class)->onWon($ref);
        } elseif ($wasWon) {
            app(Closing::class)->reopened($ref);
        }
    }

    public function note(Referral $ref, User $user, string $note): void
    {
        $team = $this->mustWork($ref, $user);
        $ref->forceFill(['last_update_at' => now()])->save();
        $this->event($ref, 'update', $team, $user, ['note' => $note]);
        $this->tellSender($ref, $team, $note);
    }

    /** The sender asks politely for an update, at most every three days. */
    public function nudge(Referral $ref, User $user): void
    {
        $team = Team::of($user);
        abort_unless($team?->id === $ref->sender_team_id && $ref->status === 'accepted' && $ref->closed_at === null, 403);
        if ($ref->last_nudged_at !== null && $ref->last_nudged_at->gt(now()->subDays(self::NUDGE_EVERY_DAYS))) {
            throw ValidationException::withMessages(['nudge' => 'You can nudge again on '.$ref->last_nudged_at->addDays(self::NUDGE_EVERY_DAYS)->toFormattedDateString().'.']);
        }
        $ref->forceFill(['last_nudged_at' => now()])->save();
        $this->event($ref, 'nudged', $team, $user);
        $ref->loadMissing(['receiver.leader', 'group.industry']);
        Events\ReferralChanged::dispatch($ref, 'progress');
        if ($ref->receiver !== null && ! Conversations::muted($ref, $ref->receiver->leader)) {
            $this->notifier->send($ref->receiver->leader, 'referral.message', ['headline' => "{$team->name} would love an update on {$ref->client_name}", 'body' => 'A quick note or stage change on the referral keeps them in the loop.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    /** Every 15 minutes: first-contact reminders, stalled-referral nudges, missed deadlines and stage time limits. */
    public function tick(): void
    {
        foreach (Referral::query()->with(['group.industry', 'receiver.leader', 'seat', 'sender.leader'])->where('status', 'accepted')->whereNull('closed_at')->get() as $ref) {
            $leader = $ref->receiver?->leader;
            if ($leader === null || $ref->accepted_at === null) {
                continue;
            }
            $industry = $ref->group->industry;
            $firstDue = $ref->accepted_at->copy()->addHours((int) $ref->group->rule('first_contact_hours'));
            if ($ref->first_contact_at === null) {
                if (now()->gte($firstDue->copy()->subHours(2)) && now()->lt($firstDue) && $this->once($ref, 'first_contact_soon', 'first')) {
                    $this->notifier->send($leader, 'referral.deadline', ['client' => $ref->client_name, 'city' => $ref->seat->territory_name, 'hours' => 2], url('/app/referrals/'.$ref->ulid), $industry, $ref->group_id);
                }
                if (now()->gte($firstDue) && $this->once($ref, 'first_contact_missed', 'first')) {
                    $this->event($ref, 'missed_first_contact', $ref->receiver, null);
                }

                continue;
            }
            $days = (int) $ref->group->rule('update_days');
            $last = $ref->last_update_at ?? $ref->first_contact_at;
            $cycle = $last->format('YmdHi');
            if (now()->gte($last->copy()->addDays($days - 1)) && now()->lt($last->copy()->addDays($days)) && $this->once($ref, 'stalled', $cycle)) {
                $this->notifier->send($leader, 'referral.message', ['headline' => "No update on {$ref->client_name} for ".($days - 1).' days', 'body' => "A quick note or stage change keeps {$ref->sender->name} in the loop. An update is due tomorrow."], url('/app/referrals/'.$ref->ulid), $industry, $ref->group_id);
            }
            if (now()->gte($last->copy()->addDays($days)) && $this->once($ref, 'update_missed', $cycle)) {
                $this->event($ref, 'missed_update', $ref->receiver, null);
            }
            $limit = (int) ($this->stage($ref)['limit_days'] ?? 0);
            if ($limit > 0 && $ref->stage_changed_at !== null && now()->gte($ref->stage_changed_at->copy()->addDays($limit)) && $this->once($ref, 'stage_limit', $ref->stage.$ref->stage_changed_at->format('YmdHi'))) {
                $label = (string) ($this->stage($ref)['label'] ?? '');
                $this->notifier->send($leader, 'referral.message', ['headline' => "{$ref->client_name} has been at “{$label}” for {$limit} days", 'body' => 'Is it time to move it on, or add a note on what’s happening?'], url('/app/referrals/'.$ref->ulid), $industry, $ref->group_id);
            }
        }
    }

    private function moveTo(Referral $ref, Team $team, User $user, string $key, ?string $note): void
    {
        $from = $ref->stage;
        $ref->forceFill(['stage' => $key, 'stage_changed_at' => now(), 'last_update_at' => now(), 'first_contact_at' => $ref->first_contact_at ?? ($key !== $this->stages($ref)[0]['key'] ? now() : null)])->save();
        $this->event($ref, 'stage', $team, $user, ['from' => $from, 'to' => $key, 'note' => $note]);
        $this->tellSender($ref, $team, $note ?? '');
    }

    private function tellSender(Referral $ref, Team $team, string $note): void
    {
        $ref->loadMissing(['sender.leader', 'group.industry']);
        Events\ReferralChanged::dispatch($ref, 'progress');
        if (Conversations::muted($ref, $ref->sender->leader)) {
            return;
        }
        $this->notifier->send($ref->sender->leader, 'referral.update', ['client' => $ref->client_name, 'team' => $team->name, 'stage' => (string) ($this->stage($ref)['label'] ?? ''), 'note' => $note], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
    }

    private function mustWork(Referral $ref, User $user): Team
    {
        $team = Team::of($user);
        abort_unless($team !== null && $team->id === $ref->receiver_team_id && $ref->status === 'accepted', 403);

        return $team;
    }

    private function once(Referral $ref, string $kind, string $cycle): bool
    {
        return DB::table('referral_reminders')->insertOrIgnore(['referral_id' => $ref->id, 'kind' => $kind, 'cycle' => $cycle, 'sent_at' => now()]) === 1;
    }

    /** @param array<string, mixed> $meta */
    private function event(Referral $ref, string $event, ?Team $team, ?User $actor, array $meta = []): void
    {
        $meta = array_filter($meta, fn ($v) => $v !== null && $v !== '');
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => $event, 'team_id' => $team?->id, 'actor_id' => $actor?->id, 'meta' => $meta === [] ? null : json_encode($meta), 'created_at' => now()]);
    }
}
