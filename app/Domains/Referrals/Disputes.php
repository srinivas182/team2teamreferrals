<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Events\ReferralChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Close and fee disputes, decided by the group owner (or an admin) in one place, with both sides' notes, the
 * referral's timeline and the signed agreement. Every decision is explained to both teams and audited.
 */
final class Disputes
{
    public function __construct(private readonly Notifier $notifier) {}

    public function disputeFee(int $feeId, User $user, string $reason): void
    {
        $fee = DB::table('referral_fees')->where('id', $feeId)->first();
        $team = Team::of($user);
        abort_unless($fee !== null && $team !== null && $team->id === (int) $fee->payer_team_id && in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);
        if ($fee->status !== 'due') {
            throw ValidationException::withMessages(['reason' => 'Only a fee that’s still due can be disputed.']);
        }
        DB::table('referral_fees')->where('id', $feeId)->update(['status' => 'disputed', 'dispute_reason' => $reason, 'disputed_at' => now(), 'updated_at' => now()]);
        $ref = Referral::query()->with(['group.owners', 'group.industry', 'sender.leader'])->findOrFail((int) $fee->referral_id);
        foreach ([$ref->sender->leader, ...$ref->group->owners->all()] as $u) {
            $this->notifier->send($u, 'referral.message', ['headline' => "Referral fee disputed: {$ref->client_name}", 'body' => "{$team->name} disputed the fee: “{$reason}”. The group owner will decide."], url('/app/earnings'), $ref->group->industry, $ref->group_id);
        }
    }

    /** @return array{closes: array<int, array<string, mixed>>, fees: array<int, array<string, mixed>>} */
    public static function forGroup(Group $group): array
    {
        $closes = Referral::query()->with(['sender', 'receiver', 'seat'])->where(['group_id' => $group->id, 'close_status' => 'disputed'])->get()
            ->map(fn (Referral $r) => ['referral' => $r->ulid, 'client' => $r->client_name, 'territory' => $r->seat->territory_name, 'sender' => $r->sender->name, 'receiver' => (string) $r->receiver?->name,
                'reason' => $r->close_dispute, 'closingDate' => $r->closing_date?->toDateString(), 'value' => $r->closing_value_cents !== null ? $r->closing_value_cents / 100 : null,
                'earnings' => $r->earnings_cents !== null ? $r->earnings_cents / 100 : null, 'agreement' => self::agreementUrl($r)])->values()->all();
        $fees = DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->join('teams as p', 'p.id', '=', 'f.payer_team_id')->join('teams as q', 'q.id', '=', 'f.payee_team_id')
            ->where('r.group_id', $group->id)->where('f.status', 'disputed')->get(['f.*', 'r.ulid', 'r.client_name', 'p.name as payer', 'q.name as payee'])
            ->map(fn ($f) => ['id' => (int) $f->id, 'referral' => (string) $f->ulid, 'client' => (string) $f->client_name, 'payer' => (string) $f->payer, 'payee' => (string) $f->payee,
                'amount' => $f->amount_cents / 100, 'basis' => $f->basis_cents !== null ? $f->basis_cents / 100 : null, 'reason' => $f->dispute_reason])->values()->all();

        return ['closes' => $closes, 'fees' => $fees];
    }

    /** Confirm the close (the fee then follows), or reopen it at the stage it was in before Won. */
    public function resolveClose(Referral $ref, User $decider, bool $confirm, string $note): void
    {
        abort_unless($ref->close_status === 'disputed', 422, 'This close isn’t disputed.');
        $ref->forceFill(['close_resolution_note' => $note])->save();
        if ($confirm) {
            app(Closing::class)->confirmAsOwner($ref, $decider);
        } else {
            // Back to the stage it was in before Won (from the stage-change event), else the first working stage.
            $wonEvent = collect(DB::table('referral_events')->where(['referral_id' => $ref->id, 'event' => 'stage'])->orderByDesc('id')->pluck('meta'))
                ->map(fn ($m) => json_decode((string) $m, true))->first(fn ($m) => is_array($m) && ($m['to'] ?? null) === $ref->stage);
            $from = is_array($wonEvent) ? (string) ($wonEvent['from'] ?? '') : '';
            $stages = app(Pipeline::class)->stages($ref);
            $open = collect($stages)->firstWhere('key', $from) ?? ($stages[1] ?? $stages[0]);
            $ref->forceFill(['stage' => $open['key'], 'stage_changed_at' => now(), 'closed_at' => null, 'closing_date' => null, 'closing_value_cents' => null, 'earnings_cents' => null, 'close_status' => null, 'close_dispute' => null])->save();
        }
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => $confirm ? 'close_upheld' : 'close_reopened', 'team_id' => null, 'actor_id' => $decider->id, 'meta' => json_encode(['note' => $note]), 'created_at' => now()]);
        $this->audit($ref->group, $decider, $confirm ? 'close-confirmed' : 'close-reopened', ['referral' => $ref->ulid, 'note' => $note]);
        $this->tellBoth($ref, $confirm ? "Close confirmed by the group owner: {$ref->client_name}" : "Referral reopened by the group owner: {$ref->client_name}", $note);
        ReferralChanged::dispatch($ref, 'closing');
    }

    /** Confirm the fee as due, adjust the amount (platform fee recalculated), or waive it. */
    public function resolveFee(int $feeId, User $decider, string $decision, string $note, ?float $amount = null): void
    {
        $fee = DB::table('referral_fees')->where('id', $feeId)->first();
        abort_unless($fee !== null && $fee->status === 'disputed', 422, 'This fee isn’t disputed.');
        $ref = Referral::query()->with(['group.industry', 'sender.leader', 'receiver.leader'])->findOrFail((int) $fee->referral_id);
        $update = ['resolution' => $decision, 'resolution_note' => $note, 'resolved_by' => $decider->id, 'resolved_at' => now(), 'updated_at' => now()];
        $update += match ($decision) {
            'confirmed' => ['status' => 'due'],
            'adjusted' => (function () use ($amount, $ref, $fee) {
                $cents = (int) round((float) $amount * 100);
                if ($cents <= 0 || $cents > (int) $fee->amount_cents) {
                    throw ValidationException::withMessages(['amount' => 'An adjusted fee must be more than $0 and no more than the original.']);
                }
                $platform = Fees::platformFee($ref, $cents);

                return ['status' => 'due', 'amount_cents' => $cents, 'platform_fee_cents' => $platform, 'platform_fee_status' => $platform > 0 ? 'pending' : 'none'];
            })(),
            'waived' => ['status' => 'waived', 'platform_fee_cents' => 0, 'platform_fee_status' => 'none'],
            default => abort(422),
        };
        DB::table('referral_fees')->where('id', $feeId)->update($update);
        $this->audit($ref->group, $decider, 'fee-'.$decision, ['fee' => $feeId, 'note' => $note, 'amount' => $update['amount_cents'] ?? null]);
        $words = ['confirmed' => 'confirmed as due', 'adjusted' => 'adjusted to $'.number_format(($update['amount_cents'] ?? 0) / 100, 2), 'waived' => 'waived'][$decision];
        $this->tellBoth($ref, "Referral fee {$words}: {$ref->client_name}", $note);
    }

    private static function agreementUrl(Referral $r): ?string
    {
        return DB::table('referral_agreements')->where(['referral_id' => $r->id, 'status' => 'signed'])->exists() ? '/app/referrals/'.$r->ulid.'/agreement.pdf' : null;
    }

    private function tellBoth(Referral $ref, string $headline, string $note): void
    {
        $ref->loadMissing(['sender.leader', 'receiver.leader', 'group.industry']);
        foreach (array_filter([$ref->sender->leader, $ref->receiver?->leader]) as $u) {
            $this->notifier->send($u, 'referral.message', ['headline' => $headline, 'body' => "The group owner’s note: {$note}"], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    /** @param array<string, mixed> $props */
    private function audit(Group $group, User $user, string $event, array $props): void
    {
        activity('group')->causedBy($user)->performedOn($group)->event('dispute-'.$event)->withProperties($props)->log("Dispute decided ({$event}) in {$group->name}");
    }
}
