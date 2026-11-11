<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Performance;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\SeatWaitlist;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Seats are earned: a holder whose service score stays below the group's threshold for the challenge period
 * (default 70 for 60 days) can be challenged by the top waitlisted team. Fair by design: a holder's first 90 days
 * are exempt, there must be enough referrals to score (5+), approved time off pauses the clock, the holder gets
 * 30 days to recover with clear targets, and can appeal the outcome to the group owner within 7 days.
 */
final class SeatChallenges
{
    public const EXEMPT_DAYS = 90;

    public const WINDOW_DAYS = 30;

    public const APPEAL_DAYS = 7;

    public function __construct(private readonly Notifier $notifier, private readonly Seats $seats) {}

    public function daily(): void
    {
        foreach (Seat::query()->with(['group', 'primary.leader'])->whereNotNull('primary_team_id')->get() as $seat) {
            $this->track($seat);
        }
        foreach (DB::table('seat_challenges')->whereIn('status', ['open', 'pending'])->get() as $c) {
            $this->advance($c);
        }
    }

    public function appeal(int $id, User $user, string $reason, string $choice): void
    {
        $c = $this->find($id);
        $holder = Team::query()->findOrFail((int) $c->holder_team_id);
        abort_unless($holder->roleOf($user) === 'leader', 403);
        if ($c->status !== 'pending' || CarbonImmutable::parse((string) $c->appeal_until)->isPast()) {
            throw ValidationException::withMessages(['reason' => 'This challenge can no longer be appealed.']);
        }
        DB::table('seat_challenges')->where('id', $id)->update(['status' => 'appealed', 'appeal_reason' => $reason, 'appealed_at' => now(), 'holder_choice' => $choice, 'updated_at' => now()]);
        $seat = Seat::query()->with('group.owners')->findOrFail((int) $c->seat_id);
        foreach ($seat->group->owners as $owner) {
            $this->notifier->send($owner, 'seat.challenge', ['headline' => "Appeal to decide: {$seat->territory_name}", 'body' => "{$holder->name} appealed the challenge outcome: “{$reason}”. The seat stays put until you decide."], url('/app/group/'.$seat->group->slug.'/challenges'));
        }
    }

    public function choose(int $id, User $user, string $choice): void
    {
        $c = $this->find($id);
        abort_unless(Team::query()->findOrFail((int) $c->holder_team_id)->roleOf($user) === 'leader', 403);
        abort_unless(in_array($c->status, ['open', 'pending', 'appealed'], true), 422);
        DB::table('seat_challenges')->where('id', $id)->update(['holder_choice' => $choice, 'updated_at' => now()]);
    }

    /** Group owner (or admin) decides an appeal: uphold moves the seat now; overturn keeps it with the holder. */
    public function decide(int $id, User $decider, bool $uphold, string $note): void
    {
        $c = $this->find($id);
        abort_unless($c->status === 'appealed' || ($decider->can('groups.manage') && in_array($c->status, ['open', 'pending'], true)), 422);
        $seat = Seat::query()->with(['group', 'primary.leader'])->findOrFail((int) $c->seat_id);
        DB::table('seat_challenges')->where('id', $id)->update(['decided_by' => $decider->id, 'decision_note' => $note, 'updated_at' => now()]);
        activity('group')->causedBy($decider)->performedOn($seat->group)->event('challenge-decided')->withProperties(['challenge' => $id, 'uphold' => $uphold, 'note' => $note])->log(($uphold ? 'Upheld' : 'Overturned').' the challenge for '.$seat->territory_name);
        if ($uphold) {
            $this->transfer($c, $decider);

            return;
        }
        $this->close($id, 'overturned');
        $seat->forceFill(['below_since' => null])->save();
        $this->tell($c, "Challenge for {$seat->territory_name} overturned", "The group owner reviewed the appeal and the seat stays with the current holder. Note: {$note}");
    }

    /** Admin override: stop a challenge (e.g. a data problem or exceptional circumstances). */
    public function cancel(int $id, User $admin, string $note): void
    {
        $c = $this->find($id);
        DB::table('seat_challenges')->where('id', $id)->update(['decided_by' => $admin->id, 'decision_note' => $note]);
        $this->close($id, 'cancelled');
        $seat = Seat::query()->findOrFail((int) $c->seat_id);
        $seat->forceFill(['below_since' => null])->save();
        activity('admin')->causedBy($admin)->event('challenge-cancelled')->withProperties(['challenge' => $id, 'note' => $note])->log('Cancelled the challenge for '.$seat->territory_name);
        $this->tell($c, "Challenge for {$seat->territory_name} cancelled", "The platform cancelled this challenge. {$note}");
    }

    private function track(Seat $seat): void
    {
        $holder = $seat->primary;
        if ($holder === null) {
            return;
        }
        $threshold = (int) $seat->group->rule('challenge_threshold');
        $score = ServiceScore::for($holder)['score'];
        if ($score === null || $score >= $threshold) {
            if ($seat->below_since !== null) {
                $seat->forceFill(['below_since' => null])->save();
            }

            return;
        }
        if ($seat->below_since === null) {
            $seat->forceFill(['below_since' => now()->toDateString()])->save();

            return;
        }
        // Approved time off pauses the clock: push the start date forward by the day.
        if (ServiceScore::onTimeOff($holder)) {
            $seat->forceFill(['below_since' => $seat->below_since->copy()->addDay()->toDateString()])->save();

            return;
        }
        $heldSince = DB::table('seat_events')->where(['seat_id' => $seat->id, 'team_id' => $holder->id])->where('event', 'claimed')->min('created_at');
        $exempt = $heldSince !== null && CarbonImmutable::parse((string) $heldSince)->gt(now()->subDays(self::EXEMPT_DAYS));
        // A holder with a strong giving record gets extra days before a challenge can start.
        $due = $seat->below_since->copy()->addDays((int) $seat->group->rule('challenge_days') + \App\Domains\Referrals\Credits::challengeGraceDays($holder, $seat->group_id))->lte(now());
        $open = DB::table('seat_challenges')->where('seat_id', $seat->id)->whereIn('status', ['open', 'pending', 'appealed'])->exists();
        if ($exempt || ! $due || $open) {
            return;
        }
        $challenger = SeatWaitlist::query()->with('team')->where('seat_id', $seat->id)->orderByDesc('score')->orderBy('joined_at')->first()?->team;
        if ($challenger === null) {
            return;
        }
        $id = DB::table('seat_challenges')->insertGetId(['seat_id' => $seat->id, 'holder_team_id' => $holder->id, 'challenger_team_id' => $challenger->id, 'status' => 'open',
            'threshold' => $threshold, 'score_at_open' => $score, 'window_ends_at' => now()->addDays(self::WINDOW_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        $tips = ServiceScore::topTips(ServiceScore::for($holder));
        $by = now()->addDays(self::WINDOW_DAYS)->toFormattedDateString();
        $this->notifier->send($holder->leader, 'seat.challenge', ['headline' => "Your {$seat->territory_name} seat has been challenged", 'body' => "Your service score has been below {$threshold} for a while (now {$score}). Reach {$threshold} by {$by} to keep the seat. ".implode(' ', $tips)], url('/app/performance'));
        $this->notifier->send($challenger->leader, 'seat.challenge', ['headline' => "You’re challenging for {$seat->territory_name}", 'body' => "The holder has until {$by} to bring their score up to {$threshold}. If they don’t, the seat is offered to you."], url('/app/performance'));
        DB::table('seat_events')->insert(['seat_id' => $seat->id, 'team_id' => $challenger->id, 'event' => 'challenged', 'meta' => json_encode(['challenge' => $id, 'score' => $score]), 'actor_id' => null, 'created_at' => now()]);
    }

    private function advance(stdClass $c): void
    {
        if ($c->status === 'open' && CarbonImmutable::parse((string) $c->window_ends_at)->lte(now())) {
            $holder = Team::query()->findOrFail((int) $c->holder_team_id);
            $score = ServiceScore::for($holder)['score'];
            $seat = Seat::query()->findOrFail((int) $c->seat_id);
            if ($score === null || $score >= (int) $c->threshold) {
                DB::table('seat_challenges')->where('id', $c->id)->update(['score_at_end' => $score]);
                $this->close((int) $c->id, 'recovered');
                $seat->forceFill(['below_since' => null])->save();
                $this->tell($c, "You kept the {$seat->territory_name} seat", 'Great work: the score is back to '.($score ?? 'a healthy level').'. The challenge is closed.');

                return;
            }
            $until = now()->addDays(self::APPEAL_DAYS);
            DB::table('seat_challenges')->where('id', $c->id)->update(['status' => 'pending', 'score_at_end' => $score, 'appeal_until' => $until, 'updated_at' => now()]);
            $this->tell($c, "The {$seat->territory_name} seat will pass on {$until->toFormattedDateString()}", "The score is {$score}, below {$c->threshold}. The holder can appeal to the group owner until then.");

            return;
        }
        if ($c->status === 'pending' && CarbonImmutable::parse((string) $c->appeal_until)->lte(now())) {
            $this->transfer($c, null);
        }
    }

    private function transfer(stdClass $c, ?User $actor): void
    {
        $seat = Seat::query()->with(['group', 'primary'])->findOrFail((int) $c->seat_id);
        // Only if nothing changed meanwhile: same holder, challenger still a team.
        if ($seat->primary_team_id !== (int) $c->holder_team_id) {
            $this->close((int) $c->id, 'cancelled');

            return;
        }
        $this->seats->transferAfterChallenge($seat, Team::query()->findOrFail((int) $c->challenger_team_id), (string) $c->holder_choice, $actor);
        $this->close((int) $c->id, 'transferred');
    }

    private function close(int $id, string $status): void
    {
        DB::table('seat_challenges')->where('id', $id)->update(['status' => $status, 'closed_at' => now(), 'updated_at' => now()]);
    }

    private function tell(stdClass $c, string $headline, string $body): void
    {
        foreach ([(int) $c->holder_team_id, (int) $c->challenger_team_id] as $tid) {
            $t = Team::query()->with('leader')->find($tid);
            if ($t !== null) {
                $this->notifier->send($t->leader, 'seat.challenge', ['headline' => $headline, 'body' => $body], url('/app/performance'));
            }
        }
    }

    private function find(int $id): stdClass
    {
        $c = DB::table('seat_challenges')->where('id', $id)->first();
        abort_if($c === null, 404);

        return $c;
    }
}
