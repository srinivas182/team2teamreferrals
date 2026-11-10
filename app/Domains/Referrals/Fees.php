<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Referral fees, in open (Tier A) industries only. The referring team earns a percentage of the receiving team's
 * earnings from the referral (e.g. 30% of their commission), or a flat amount; the group owner sets the default and the
 * two teams can agree a different fee for one referral before signing. When a Won close is confirmed the fee is
 * calculated and a statement goes to both; the receiving team pays the referring team directly. The platform fee
 * (admin rule: a percentage of, or flat amount from, the referral fee) is owed by the referring team once they confirm
 * they received the referral fee. Team to Team never holds the referral money.
 */
final class Fees
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return array{type: string, value: int}|null value: basis points (percent) or cents (flat) */
    public static function groupDefault(Group $group): ?array
    {
        $f = data_get($group->rules, 'referral_fee');

        return $group->industry->tier === Tier::Open && is_array($f) && isset($f['type'], $f['value']) ? ['type' => (string) $f['type'], 'value' => (int) $f['value']] : null;
    }

    /** @return array{type: string, value: int}|null */
    public static function forReferral(Referral $ref): ?array
    {
        if ($ref->group->industry->tier !== Tier::Open) {
            return null;
        }

        return $ref->fee_type !== null ? ['type' => $ref->fee_type, 'value' => (int) $ref->fee_value] : self::groupDefault($ref->group);
    }

    /**
     * The fee in plain words. In the agreement ($legal) it names the parties and says gross earnings, so there's
     * no doubt what the percentage applies to; on screens it says "the receiving team's earnings".
     *
     * @param  array{type: string, value: int}|null  $fee
     */
    public static function describe(?array $fee, bool $legal = false): string
    {
        if ($legal && ($fee['type'] ?? null) === 'percent') {
            return rtrim(rtrim(number_format($fee['value'] / 100, 2), '0'), '.').'% of the gross earnings the receiving party receives from this referral (for example, its commission)';
        }

        return match ($fee['type'] ?? null) {
            'percent' => rtrim(rtrim(number_format($fee['value'] / 100, 2), '0'), '.').'% of the receiving team’s earnings from this referral',
            'flat' => '$'.number_format($fee['value'] / 100, 2).' per referral that closes',
            default => 'as agreed between the parties',
        };
    }

    public function setGroupDefault(Group $group, User $user, ?string $type, ?float $value, int $dueDays): void
    {
        if ($group->industry->tier !== Tier::Open) {
            throw ValidationException::withMessages(['type' => 'Referral fees aren’t allowed in this industry.']);
        }
        $fee = $type === null ? null : ['type' => $type, 'value' => self::units($type, (float) $value)];
        $group->forceFill(['rules' => [...$group->rules, 'referral_fee' => $fee, 'fee_due_days' => $dueDays, 'fee_terms' => self::describe($fee)]])->save();
        activity('group')->causedBy($user)->performedOn($group)->event('fee-default')->withProperties(['fee' => $fee, 'due_days' => $dueDays])->log('Set the referral fee for '.$group->name.': '.self::describe($fee));
    }

    /** Either team proposes a fee for this referral; only before anyone has signed its agreement. */
    public function propose(Referral $ref, User $user, string $type, float $value): void
    {
        $team = $this->side($ref, $user);
        $this->mustBeBeforeSigning($ref);
        $ref->forceFill(['fee_proposal' => ['type' => $type, 'value' => self::units($type, $value), 'by' => $team->id]])->save();
        $other = $team->id === $ref->sender_team_id ? $ref->receiver : $ref->sender;
        if ($other !== null) {
            $this->notifier->send($other->leader, 'referral.message', ['headline' => "{$team->name} proposed a fee for {$ref->client_name}", 'body' => self::describe(['type' => $type, 'value' => self::units($type, $value)]).'. Accept it on the referral before signing the agreement.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    public function acceptProposal(Referral $ref, User $user): void
    {
        $team = $this->side($ref, $user);
        $p = $ref->fee_proposal;
        abort_unless(is_array($p) && (int) $p['by'] !== $team->id, 422, 'There is no proposal from the other team to accept.');
        $this->mustBeBeforeSigning($ref);
        $ref->forceFill(['fee_type' => $p['type'], 'fee_value' => (int) $p['value'], 'fee_proposal' => null])->save();
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => 'fee_agreed', 'team_id' => $team->id, 'actor_id' => $user->id, 'meta' => json_encode(['fee' => self::describe(self::forReferral($ref))]), 'created_at' => now()]);
        // The agreement nobody has signed yet is regenerated, so it states the fee actually agreed.
        app(ReferralAgreements::class)->regenerate($ref);
    }

    /** When a Won close is confirmed: calculate the fee and send a statement to both teams. */
    public function create(Referral $ref): void
    {
        $ref->loadMissing(['group.industry', 'sender.leader', 'receiver.leader', 'seat']);
        $fee = self::forReferral($ref);
        if ($fee === null || $ref->receiver === null || DB::table('referral_fees')->where('referral_id', $ref->id)->exists()) {
            return;
        }
        // Percentage fees are based on the receiving team's earnings from the referral (never the sale or job price).
        $amount = $fee['type'] === 'flat' ? $fee['value'] : (int) round(($ref->earnings_cents ?? 0) * $fee['value'] / 10000);
        if ($amount <= 0) {
            return;
        }
        $platform = self::platformFee($ref, $amount);
        $id = DB::table('referral_fees')->insertGetId([
            'referral_id' => $ref->id, 'payer_team_id' => $ref->receiver->id, 'payee_team_id' => $ref->sender_team_id, 'type' => $fee['type'], 'rate' => $fee['value'],
            'basis_cents' => $fee['type'] === 'percent' ? $ref->earnings_cents : null, 'amount_cents' => $amount,
            'platform_fee_cents' => $platform, 'platform_fee_status' => $platform > 0 ? 'pending' : 'none', 'due_on' => now()->addDays((int) data_get($ref->group->rules, 'fee_due_days', 10))->toDateString(),
            'status' => 'due', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->fee((int) $id);
        $path = 'fees/'.$ref->ulid.'.pdf';
        Storage::disk('private')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.fee-statement', ['ref' => $ref, 'fee' => $row, 'terms' => self::describe($fee)])->output());
        DB::table('referral_fees')->where('id', $id)->update(['statement_path' => $path]);
        $money = '$'.number_format($amount / 100, 2);
        $this->notifier->send($ref->receiver->leader, 'referral.message', ['headline' => "Referral fee due: {$money} to {$ref->sender->name}", 'body' => "For {$ref->client_name} ({$this->due($row)}). Pay them directly, then mark it paid on the Earnings page."], url('/app/earnings'), $ref->group->industry, $ref->group_id);
        $net = $platform > 0 ? ' Platform fee $'.number_format($platform / 100, 2).', so you net $'.number_format(($amount - $platform) / 100, 2).'.' : '';
        $this->notifier->send($ref->sender->leader, 'referral.message', ['headline' => "Referral fee: {$money} from {$ref->receiver->name}", 'body' => "For {$ref->client_name} ({$this->due($row)}).{$net} The statement is on the Earnings page."], url('/app/earnings'), $ref->group->industry, $ref->group_id);
    }

    public function markPaid(int $feeId, User $user, ?string $reference): void
    {
        $fee = $this->fee($feeId);
        abort_unless($this->onTeam($user, (int) $fee->payer_team_id) && $fee->status === 'due', 403);
        DB::table('referral_fees')->where('id', $feeId)->update(['status' => 'paid', 'paid_at' => now(), 'paid_reference' => $reference, 'updated_at' => now()]);
        $payee = Team::query()->with('leader')->findOrFail((int) $fee->payee_team_id);
        $this->notifier->send($payee->leader, 'referral.message', ['headline' => 'A referral fee was marked paid', 'body' => '$'.number_format($fee->amount_cents / 100, 2).($reference ? " (reference {$reference})" : '').'. Please confirm when you’ve received it.'], url('/app/earnings'));
    }

    public function markReceived(int $feeId, User $user): void
    {
        $fee = $this->fee($feeId);
        abort_unless($this->onTeam($user, (int) $fee->payee_team_id) && in_array($fee->status, ['due', 'paid'], true), 403);
        // The referring team has the money now: the platform fee from it becomes owed (billed with their account, Sprint 22).
        DB::table('referral_fees')->where('id', $feeId)->update(['status' => 'received', 'received_at' => now(), 'updated_at' => now(),
            'platform_fee_status' => (int) $fee->platform_fee_cents > 0 ? 'owed' : 'none', 'platform_fee_owed_at' => (int) $fee->platform_fee_cents > 0 ? now() : null]);
    }

    /** Daily: a reminder on the due date and another a week later (once each). */
    public function remind(): void
    {
        foreach (DB::table('referral_fees')->where('status', 'due')->where('due_on', '<=', now()->toDateString())->get() as $fee) {
            $overdue = now()->toDateString() >= now()->parse((string) $fee->due_on)->addDays(7)->toDateString();
            $kind = $overdue ? 'fee_overdue' : 'fee_due';
            if (DB::table('referral_reminders')->insertOrIgnore(['referral_id' => $fee->referral_id, 'kind' => $kind, 'cycle' => 'fee', 'sent_at' => now()]) !== 1) {
                continue;
            }
            $payer = Team::query()->with('leader')->findOrFail((int) $fee->payer_team_id);
            $this->notifier->send($payer->leader, 'referral.message', ['headline' => ($overdue ? 'Overdue: ' : 'Due today: ').'referral fee of $'.number_format($fee->amount_cents / 100, 2), 'body' => 'Pay the referring team directly, then mark it paid on the Earnings page.'], url('/app/earnings'));
        }
    }

    /** CSV for the team's accountant: fees paid and received in a month. */
    public static function csv(Team $team, string $month): string
    {
        $rows = DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->join('teams as p', 'p.id', '=', 'f.payer_team_id')->join('teams as q', 'q.id', '=', 'f.payee_team_id')
            ->where(fn ($w) => $w->where('f.payer_team_id', $team->id)->orWhere('f.payee_team_id', $team->id))->where('f.created_at', 'like', $month.'%')->orderBy('f.created_at')
            ->get(['f.*', 'r.ulid', 'r.client_name', 'p.name as payer', 'q.name as payee']);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['Date', 'Reference', 'Client', 'Direction', 'Other team', 'Receiving team earnings', 'Referral fee', 'Platform fee', 'Net', 'Due', 'Status', 'Paid on', 'Payment reference'], escape: '\\');
        foreach ($rows as $r) {
            $in = (int) $r->payee_team_id === $team->id;
            fputcsv($out, [substr((string) $r->created_at, 0, 10), $r->ulid, $r->client_name, $in ? 'Receivable' : 'Payable', $in ? $r->payer : $r->payee,
                $r->basis_cents !== null ? number_format($r->basis_cents / 100, 2, '.', '') : '', number_format($r->amount_cents / 100, 2, '.', ''),
                $in ? number_format($r->platform_fee_cents / 100, 2, '.', '') : '', $in ? number_format(($r->amount_cents - $r->platform_fee_cents) / 100, 2, '.', '') : '', $r->due_on, $r->status, substr((string) $r->paid_at, 0, 10), $r->paid_reference], escape: '\\');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** The platform fee from a referral fee: the group's rule, else the industry's; Tier A only; never more than the fee. */
    public static function platformFee(Referral $ref, int $referralFeeCents): int
    {
        if (! $ref->group->industry->tier->allowsPlatformFee()) {
            return 0;
        }
        $rule = \App\Domains\Network\Industries\Models\PlatformFeeRule::query()->where(['industry_id' => $ref->group->industry_id, 'enabled' => true])
            ->where(fn ($q) => $q->where('group_id', $ref->group_id)->orWhereNull('group_id'))->orderByRaw('group_id is null')->first();
        $cents = match ($rule?->mode) {
            'percent' => (int) round($referralFeeCents * (float) $rule->percent / 100),
            'flat' => (int) $rule->flat_cents,
            default => 0,
        };

        return min($cents, $referralFeeCents);
    }

    public static function units(string $type, float $value): int
    {
        if ($type === 'percent' && ($value <= 0 || $value > 90)) {
            throw ValidationException::withMessages(['value' => 'A percentage between 0 and 90.']);
        }
        if ($type === 'flat' && ($value <= 0 || $value > 1000000)) {
            throw ValidationException::withMessages(['value' => 'An amount between $1 and $1,000,000.']);
        }

        return (int) round($value * 100); // basis points for percent, cents for flat
    }

    private function mustBeBeforeSigning(Referral $ref): void
    {
        if ($ref->group->industry->tier !== Tier::Open) {
            throw ValidationException::withMessages(['value' => 'Referral fees aren’t allowed in this industry.']);
        }
        $a = DB::table('referral_agreements')->where('referral_id', $ref->id)->first();
        if ($a !== null && ($a->sender_signed_at !== null || $a->receiver_signed_at !== null || $a->status === 'signed')) {
            throw ValidationException::withMessages(['value' => 'The agreement has been signed, so the fee can’t change.']);
        }
    }

    private function side(Referral $ref, User $user): Team
    {
        $ref->loadMissing(['group.industry', 'sender.leader', 'receiver.leader']);
        $team = Team::of($user);
        abort_unless($team !== null && in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true) && in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);

        return $team;
    }

    private function onTeam(User $user, int $teamId): bool
    {
        $team = Team::of($user);

        return $team !== null && $team->id === $teamId && in_array($team->roleOf($user), ['leader', 'assistant'], true);
    }

    private function fee(int $id): stdClass
    {
        $f = DB::table('referral_fees')->where('id', $id)->first();
        abort_if($f === null, 404);

        return $f;
    }

    private function due(stdClass $fee): string
    {
        return 'due '.now()->parse((string) $fee->due_on)->toFormattedDateString();
    }
}
