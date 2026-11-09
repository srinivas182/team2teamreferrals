<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\SeatWaitlist;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The seat engine: applications, approvals, backup, waitlist, offers and the payment lifecycle
 * (trial → active → grace → inactive → released). Every step is recorded in seat history and the team,
 * the group's owners and the platform owners are told by email and in-app.
 */
final class Seats
{
    public function __construct(private readonly Notifier $notifier) {}

    public function apply(Group $group, Team $team, City $city, ?string $licenseNumber, ?User $actor): int
    {
        if ($group->visibility === 'invite_only' || $group->is_private) {
            throw ValidationException::withMessages(['group' => 'This group is invite only.']);
        }
        $territory = Territory::fromCity($group->industry->territory_unit, $city);
        $this->checkLicense($group, $territory['state_code'], $licenseNumber);
        $seat = Seat::query()->firstOrCreate(['group_id' => $group->id, 'territory_type' => $territory['territory_type'], 'territory_id' => $territory['territory_id']], $territory);
        if ($seat->primary_team_id === $team->id || $seat->backup_team_id === $team->id || SeatWaitlist::query()->where(['seat_id' => $seat->id, 'team_id' => $team->id])->exists()
            || DB::table('seat_applications')->where(['seat_id' => $seat->id, 'team_id' => $team->id, 'status' => 'pending'])->exists()) {
            throw ValidationException::withMessages(['city' => 'You already hold, back up, wait for or have applied for this seat.']);
        }
        $id = (int) DB::table('seat_applications')->insertGetId(['group_id' => $group->id, 'team_id' => $team->id, 'seat_id' => $seat->id, 'license_number' => $licenseNumber, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->event($seat, $team, 'applied', ['application' => $id], $actor);
        $this->tellOwners($group, 'New application', "{$team->name} applied for {$seat->territory_name}.");
        if ($group->rule('auto_approve') === true) {
            $this->approve($id, null);
        }

        return $id;
    }

    /** @return string primary, backup or waitlist */
    public function approve(int $applicationId, ?User $actor): string
    {
        return DB::transaction(function () use ($applicationId, $actor) {
            $app = $this->row('seat_applications', $applicationId, true);
            abort_if($app === null || $app->status !== 'pending', 422, 'This application has already been decided.');
            $seat = Seat::query()->lockForUpdate()->findOrFail((int) $app->seat_id);
            $team = Team::query()->findOrFail((int) $app->team_id);
            $outcome = match (true) {
                $seat->primary_team_id === null => $this->makePrimary($seat, $team, $actor),
                $seat->backup_team_id === null => $this->makeBackup($seat, $team, $actor),
                default => $this->addToWaitlist($seat, $team, $app->license_number !== null),
            };
            DB::table('seat_applications')->where('id', $applicationId)->update(['status' => 'approved', 'outcome' => $outcome, 'decided_by' => $actor?->id, 'decided_at' => now(), 'updated_at' => now()]);
            $text = ['primary' => "You hold the {$seat->territory_name} seat. Your free trial runs until ".$seat->trial_ends_at?->toFormattedDateString().'.', 'backup' => "You’re the backup for {$seat->territory_name}: you get its referrals whenever the holder can’t take them, and you’re offered the seat first if it opens.", 'waitlist' => "You’re on the waitlist for {$seat->territory_name}. We’ll offer you the seat when it opens."][$outcome];
            $this->tellTeam($team, $seat, 'seat.decision', ['primary' => 'Seat approved', 'backup' => 'Approved as backup', 'waitlist' => 'Approved: you’re on the waitlist'][$outcome], $text);
            $this->tellOwners($seat->group, 'Application approved', "{$team->name} → {$seat->territory_name} ({$outcome}).");

            return $outcome;
        });
    }

    public function decline(int $applicationId, User $actor, string $reason): void
    {
        $app = $this->row('seat_applications', $applicationId);
        abort_if($app === null || $app->status !== 'pending', 422, 'This application has already been decided.');
        DB::table('seat_applications')->where('id', $applicationId)->update(['status' => 'declined', 'decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
        $seat = Seat::query()->findOrFail((int) $app->seat_id);
        $team = Team::query()->findOrFail((int) $app->team_id);
        $this->event($seat, $team, 'declined', ['reason' => $reason], $actor);
        $this->tellTeam($team, $seat, 'seat.decision', 'Application declined', "Your application for {$seat->territory_name} was declined: {$reason}");
    }

    // ---- Payment lifecycle (Sprint 22 billing calls these; the daily job moves seats on) ----

    public function paymentFailed(Seat $seat): void
    {
        $team = $seat->primary;
        if (! in_array($seat->status, ['trial', 'active'], true) || $team === null) {
            return;
        }
        $seat->forceFill(['status' => 'grace', 'grace_started_at' => now()])->save();
        $this->event($seat, $team, 'payment_failed');
        $days = (int) $seat->group->rule('grace_days');
        $this->tellTeam($team, $seat, 'seat.payment', "Payment problem: your {$seat->territory_name} seat", "We couldn’t take this month’s payment. Update your card within {$days} days to keep receiving referrals for {$seat->territory_name}.", url('/app/billing'));
        $this->tellOwners($seat->group, 'Seat in grace', "{$team->name} ({$seat->territory_name}): payment failed, {$days} days’ grace.");
    }

    public function paymentSucceeded(Seat $seat): void
    {
        $team = $seat->primary;
        if (! in_array($seat->status, ['grace', 'inactive'], true) || $team === null) {
            return;
        }
        $seat->forceFill(['status' => 'active', 'grace_started_at' => null, 'inactive_at' => null])->save();
        $this->event($seat, $team, 'reactivated');
        $this->tellTeam($team, $seat, 'seat.payment', "Your {$seat->territory_name} seat is active again", 'Thanks: payment received. Referrals for your seat are coming to you again.', url('/app'));
        $this->tellOwners($seat->group, 'Seat reactivated', "{$team->name} ({$seat->territory_name}) paid and is active again.");
    }

    /** Daily: grace → inactive, inactive → released, and expired offers pass to the next team. */
    public function tick(): void
    {
        foreach (Seat::query()->with(['group', 'primary'])->where('status', 'grace')->get() as $seat) {
            if ($seat->grace_started_at?->lessThanOrEqualTo(now()->subDays((int) $seat->group->rule('grace_days')))) {
                $seat->forceFill(['status' => 'inactive', 'inactive_at' => now()])->save();
                $this->event($seat, $seat->primary, 'inactive');
                $left = (int) $seat->group->rule('release_days') - (int) $seat->group->rule('grace_days');
                if ($seat->primary) {
                    $this->tellTeam($seat->primary, $seat, 'seat.payment', "Your {$seat->territory_name} seat is inactive", "New referrals now go to the backup team. You can still work the referrals you already accepted, and you’re not charged while inactive. Pay within {$left} days to keep the seat.", url('/app/billing'));
                }
                $this->tellOwners($seat->group, 'Seat inactive', ($seat->primary->name ?? 'A team')." ({$seat->territory_name}) is inactive; referrals go to the backup.");
            }
        }
        foreach (Seat::query()->with(['group', 'primary'])->where('status', 'inactive')->get() as $seat) {
            if ($seat->grace_started_at?->lessThanOrEqualTo(now()->subDays((int) $seat->group->rule('release_days')))) {
                $this->release($seat, 'unpaid');
            }
        }
        foreach (DB::table('seat_offers')->where('status', 'pending')->where('expires_at', '<=', now())->get() as $offer) {
            DB::table('seat_offers')->where('id', $offer->id)->update(['status' => 'expired', 'responded_at' => now(), 'updated_at' => now()]);
            $seat = Seat::query()->with('group')->findOrFail((int) $offer->seat_id);
            $this->event($seat, Team::query()->find((int) $offer->team_id), 'offer_expired');
            $this->offerNext($seat, (int) $offer->team_id);
        }
    }

    public function release(Seat $seat, string $reason, ?User $actor = null): void
    {
        $former = $seat->primary;
        $seat->forceFill(['status' => 'open', 'primary_team_id' => null, 'founding' => false, 'trial_ends_at' => null, 'grace_started_at' => null, 'inactive_at' => null])->save();
        $this->event($seat, $former, 'released', ['reason' => $reason], $actor);
        if ($former) {
            $this->tellTeam($former, $seat, 'seat.payment', "Your {$seat->territory_name} seat was released", 'The seat has been offered to the next team. Your account and history are kept, and you can rejoin the waitlist at any time.', url('/app'));
        }
        $this->tellOwners($seat->group, 'Seat released', ($former->name ?? 'A team')." no longer holds {$seat->territory_name} ({$reason}).");
        $this->offerNext($seat, null);
    }

    public function acceptOffer(int $offerId, Team $team): void
    {
        DB::transaction(function () use ($offerId, $team) {
            $offer = $this->row('seat_offers', $offerId, true);
            abort_if($offer === null || (int) $offer->team_id !== $team->id, 404);
            abort_if($offer->status !== 'pending' || now()->greaterThan((string) $offer->expires_at), 422, 'This offer has expired.');
            $seat = Seat::query()->lockForUpdate()->with('group')->findOrFail((int) $offer->seat_id);
            DB::table('seat_offers')->where('id', $offerId)->update(['status' => 'accepted', 'responded_at' => now(), 'updated_at' => now()]);
            if ($seat->backup_team_id === $team->id) {
                $seat->backup_team_id = null;
            }
            SeatWaitlist::query()->where(['seat_id' => $seat->id, 'team_id' => $team->id])->delete();
            $this->makePrimary($seat, $team, $team->leader);
            $this->promoteBackup($seat);
            $this->tellTeam($team, $seat, 'seat.decision', 'Seat claimed', "You hold the {$seat->territory_name} seat. Your free trial runs until ".$seat->trial_ends_at?->toFormattedDateString().'.');
            $this->tellOwners($seat->group, 'Seat claimed', "{$team->name} claimed {$seat->territory_name}.");
        });
    }

    public function declineOffer(int $offerId, Team $team): void
    {
        $offer = $this->row('seat_offers', $offerId);
        abort_if($offer === null || (int) $offer->team_id !== $team->id || $offer->status !== 'pending', 404);
        DB::table('seat_offers')->where('id', $offerId)->update(['status' => 'declined', 'responded_at' => now(), 'updated_at' => now()]);
        $seat = Seat::query()->with('group')->findOrFail((int) $offer->seat_id);
        $this->event($seat, $team, 'offer_declined');
        $this->offerNext($seat, $team->id);
    }

    // ---- internals ----

    private function makePrimary(Seat $seat, Team $team, ?User $actor): string
    {
        $months = (int) ($seat->group->pricing['trial_months'] ?? 3);
        $seat->forceFill(['primary_team_id' => $team->id, 'status' => $months > 0 ? 'trial' : 'active', 'trial_ends_at' => now()->addMonths($months),
            'founding' => $seat->group->status !== 'live', 'grace_started_at' => null, 'inactive_at' => null])->save();
        $this->event($seat, $team, 'claimed', ['founding' => $seat->founding, 'trial_ends_at' => $seat->trial_ends_at?->toDateString()], $actor);

        return 'primary';
    }

    private function makeBackup(Seat $seat, Team $team, ?User $actor): string
    {
        $seat->forceFill(['backup_team_id' => $team->id])->save();
        $this->event($seat, $team, 'backup', [], $actor);

        return 'backup';
    }

    private function addToWaitlist(Seat $seat, Team $team, bool $licensed): string
    {
        // Ranking until the trust score exists (Sprint 34): verified licenses first (Licenses::rankScore), then time waited.
        SeatWaitlist::query()->firstOrCreate(['seat_id' => $seat->id, 'team_id' => $team->id], ['score' => max(\App\Domains\Network\Licenses\Licenses::rankScore($team), $licensed ? 20 : 10), 'joined_at' => now()]);
        $this->event($seat, $team, 'waitlisted');

        return 'waitlist';
    }

    /** When the backup claims the seat, the top waitlisted team becomes the new backup automatically. */
    private function promoteBackup(Seat $seat): void
    {
        if ($seat->backup_team_id !== null) {
            return;
        }
        $next = $seat->waitlist()->with('team')->first();
        if ($next !== null) {
            $seat->forceFill(['backup_team_id' => $next->team_id])->save();
            $next->delete();
            $this->event($seat, $next->team, 'backup');
            $this->tellTeam($next->team, $seat, 'seat.decision', 'You’re now the backup', "You moved up: you’re the backup for {$seat->territory_name}.");
        }
    }

    /** Offer an open seat to the backup first, then the waitlist in rank order; skips the team that just passed. */
    private function offerNext(Seat $seat, ?int $skipTeamId): void
    {
        if ($seat->primary_team_id !== null) {
            return;
        }
        $offered = DB::table('seat_offers')->where('seat_id', $seat->id)->whereIn('status', ['expired', 'declined'])->pluck('team_id')->all();
        $candidates = array_filter([$seat->backup_team_id, ...$seat->waitlist()->pluck('team_id')->all()], fn ($id) => $id !== null && $id !== $skipTeamId && ! in_array($id, $offered, true));
        $teamId = array_values($candidates)[0] ?? null;
        if ($teamId === null) {
            $this->tellOwners($seat->group, 'Seat open', "{$seat->territory_name} is open: nobody is waiting for it.");

            return;
        }
        $team = Team::query()->findOrFail((int) $teamId);
        $hours = (int) $seat->group->rule('offer_hours');
        $id = DB::table('seat_offers')->insertGetId(['seat_id' => $seat->id, 'team_id' => $team->id, 'status' => 'pending', 'expires_at' => now()->addHours($hours), 'created_at' => now(), 'updated_at' => now()]);
        $this->event($seat, $team, 'offered', ['offer' => $id, 'hours' => $hours]);
        $this->notifier->send($team->leader, 'seat.offered', ['city' => $seat->territory_name, 'industry' => strtolower($seat->group->industry->name), 'hours' => $hours], url('/app/seats/offers/'.$id), $seat->group->industry);
        $this->tellOwners($seat->group, 'Seat offered', "{$seat->territory_name} offered to {$team->name} ({$hours} hours to claim).");
    }

    private function checkLicense(Group $group, string $stateCode, ?string $number): void
    {
        if (! $group->industry->license_required) {
            return;
        }
        if ($number === null || trim($number) === '') {
            throw ValidationException::withMessages(['license_number' => 'This industry needs your license number for the seat’s state.']);
        }
        $rule = IndustryLicensing::query()->where('industry_id', $group->industry_id)->whereHas('state', fn ($q) => $q->where('code', $stateCode))->first();
        if ($rule instanceof IndustryLicensing && ! $rule->accepts($number)) {
            throw ValidationException::withMessages(['license_number' => "That doesn’t look like a {$stateCode} license number."]);
        }
    }

    private function row(string $table, int $id, bool $lock = false): ?stdClass
    {
        $q = DB::table($table)->where('id', $id);
        /** @var ?stdClass $row */
        $row = ($lock ? $q->lockForUpdate() : $q)->first();

        return $row;
    }

    /** @param array<string, mixed> $meta */
    private function event(Seat $seat, ?Team $team, string $event, array $meta = [], ?User $actor = null): void
    {
        DB::table('seat_events')->insert(['seat_id' => $seat->id, 'team_id' => $team?->id, 'event' => $event, 'meta' => $meta === [] ? null : json_encode($meta), 'actor_id' => $actor?->id, 'created_at' => now()]);
    }

    private function tellTeam(Team $team, Seat $seat, string $type, string $headline, string $body, ?string $url = null): void
    {
        $this->notifier->send($team->leader, $type, ['group' => $seat->group->name, 'headline' => $headline, 'body' => $body], $url ?? url('/app'), $seat->group->industry);
    }

    /** Group owners and platform owners (super admins) hear about every seat change. */
    private function tellOwners(Group $group, string $headline, string $body): void
    {
        $people = $group->owners()->get()->merge(User::role('super_admin')->get())->unique('id');
        foreach ($people as $u) {
            /** @var User $u */
            $this->notifier->send($u, 'seat.update', ['group' => $group->name, 'headline' => $headline, 'body' => $body], url('/admin/groups/'.$group->slug), $group->industry);
        }
    }
}
