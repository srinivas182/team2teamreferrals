<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Events\ReferralChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * After a referral closes: the sender confirms a Won close (or disputes it; it confirms itself after 7 days), both
 * sides rate each other, the client is asked one optional question, and the receiver can thank the sender.
 */
final class Closing
{
    public const CONFIRM_DAYS = 7;

    public const EDIT_DAYS = 7;

    public function __construct(private readonly Notifier $notifier) {}

    public function onWon(Referral $ref): void
    {
        $ref->forceFill(['close_status' => 'awaiting', 'close_confirmed_at' => null, 'close_dispute' => null])->save();
        $ref->loadMissing(['sender.leader', 'receiver', 'group.industry']);
        $value = $ref->closing_value_cents !== null ? ' at $'.number_format($ref->closing_value_cents / 100, 2) : '';
        $this->notifier->send($ref->sender->leader, 'referral.message', ['headline' => "{$ref->receiver?->name} closed {$ref->client_name}{$value}: please confirm", 'body' => 'Confirm the close (or tell us if something’s not right). It confirms itself after '.self::CONFIRM_DAYS.' days.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
    }

    public function reopened(Referral $ref): void
    {
        $ref->forceFill(['close_status' => null, 'close_confirmed_at' => null, 'close_dispute' => null])->save();
    }

    public function confirm(Referral $ref, User $user): void
    {
        $this->mustBeSender($ref, $user);
        abort_unless(in_array($ref->close_status, ['awaiting', 'disputed'], true), 422, 'There is nothing to confirm.');
        $this->confirmed($ref, 'confirmed', $user);
    }

    /** The group owner (or an admin) upholds a disputed close; the fee and the client's question follow. */
    public function confirmAsOwner(Referral $ref, User $decider): void
    {
        $this->confirmed($ref, 'confirmed', $decider);
    }

    public function dispute(Referral $ref, User $user, string $reason): void
    {
        $this->mustBeSender($ref, $user);
        abort_unless($ref->close_status === 'awaiting', 422, 'This close can no longer be disputed.');
        $ref->forceFill(['close_status' => 'disputed', 'close_dispute' => $reason])->save();
        $this->event($ref, 'close_disputed', $ref->sender_team_id, $user, ['reason' => $reason]);
        $ref->loadMissing(['receiver.leader', 'group.owners', 'group.industry']);
        foreach (array_filter([$ref->receiver?->leader, ...$ref->group->owners->all()]) as $u) {
            $this->notifier->send($u, 'referral.message', ['headline' => "Close disputed: {$ref->client_name}", 'body' => "{$ref->sender->name} disputed the close: “{$reason}”."], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
        ReferralChanged::dispatch($ref, 'closing');
    }

    /** Daily: Won closes nobody confirmed or disputed confirm themselves. */
    public function autoConfirm(): void
    {
        foreach (Referral::query()->with(['group.industry', 'seat', 'sender', 'receiver'])->where('close_status', 'awaiting')->where('closed_at', '<=', now()->subDays(self::CONFIRM_DAYS))->get() as $ref) {
            $this->confirmed($ref, 'auto_confirmed', null);
        }
    }

    /**
     * @param  array<string, int>  $aspects
     */
    public function rate(Referral $ref, User $user, int $stars, array $aspects, ?string $comment): void
    {
        $team = Team::of($user);
        $side = match (true) {
            $team?->id === $ref->sender_team_id => 'sender',
            $team?->id === $ref->receiver_team_id => 'receiver',
            default => null,
        };
        abort_if($side === null || $team === null, 403);
        abort_unless($ref->closed_at !== null, 422, 'You can rate once the referral is closed.');
        $rated = $side === 'sender' ? $ref->receiver_team_id : $ref->sender_team_id;
        $ratedTeam = Team::query()->find($rated);
        abort_if($ratedTeam === null, 422);
        // Integrity: never rate your own company.
        if ($team->company_id !== null && $team->company_id === $ratedTeam->company_id) {
            throw ValidationException::withMessages(['stars' => 'Teams in the same company can’t rate each other.']);
        }
        $existing = DB::table('referral_ratings')->where(['referral_id' => $ref->id, 'rater' => $side])->first();
        if ($existing !== null && now()->gt((string) $existing->editable_until)) {
            throw ValidationException::withMessages(['stars' => 'Ratings can be changed for '.self::EDIT_DAYS.' days.']);
        }
        DB::table('referral_ratings')->updateOrInsert(['referral_id' => $ref->id, 'rater' => $side], [
            'rater_team_id' => $team->id, 'rated_team_id' => $rated, 'stars' => max(1, min(5, $stars)), 'aspects' => $aspects === [] ? null : json_encode($aspects),
            'comment' => $comment, 'editable_until' => $existing->editable_until ?? now()->addDays(self::EDIT_DAYS), 'updated_at' => now(), 'created_at' => $existing->created_at ?? now(),
        ]);
        ReferralChanged::dispatch($ref, 'rating');
    }

    public function rateAsClient(string $token, int $stars, ?string $comment): Referral
    {
        $ref = Referral::query()->where('client_rating_token_hash', hash('sha256', $token))->firstOrFail();
        DB::table('referral_ratings')->insertOrIgnore(['referral_id' => $ref->id, 'rater' => 'client', 'rater_team_id' => null, 'rated_team_id' => $ref->receiver_team_id,
            'stars' => max(1, min(5, $stars)), 'comment' => $comment, 'editable_until' => null, 'created_at' => now(), 'updated_at' => now()]);
        $ref->forceFill(['client_rating_token_hash' => null])->save();

        return $ref;
    }

    public function thank(Referral $ref, User $user, string $note): void
    {
        abort_unless(Team::of($user)?->id === $ref->receiver_team_id && $ref->close_status !== null, 403);
        $ref->forceFill(['thanks_note' => $note, 'thanks_at' => now()])->save();
        $ref->loadMissing(['sender.leader', 'receiver', 'group.industry']);
        $this->notifier->send($ref->sender->leader, 'referral.message', ['headline' => "{$ref->receiver?->name} says thank you", 'body' => "“{$note}” You can show it on your profile from the referral page."], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        ReferralChanged::dispatch($ref, 'closing');
    }

    public function setThanksPublic(Referral $ref, User $user, bool $public): void
    {
        $this->mustBeSender($ref, $user);
        abort_unless($ref->thanks_note !== null, 422);
        $ref->forceFill(['thanks_public' => $public])->save();
    }

    private function confirmed(Referral $ref, string $how, ?User $user): void
    {
        $ref->forceFill(['close_status' => $how, 'close_confirmed_at' => now(), 'close_dispute' => null])->save();
        $this->event($ref, 'close_'.$how, $ref->sender_team_id, $user);
        app(Fees::class)->create($ref);
        Credits::onWonConfirmed($ref);
        $this->askClient($ref);
        ReferralChanged::dispatch($ref, 'closing');
    }

    /** One optional question for the client: by email if we have it; by text only where texting is approved and they confirmed. */
    private function askClient(Referral $ref): void
    {
        $ref->loadMissing(['group.industry', 'receiver']);
        $token = Str::random(40);
        $ref->forceFill(['client_rating_token_hash' => hash('sha256', $token)])->save();
        $link = url('/rate/'.$token);
        $pro = (string) $ref->receiver?->name;
        if ($ref->client_email !== null) {
            Notification::route('mail', $ref->client_email)->notify(new ClientRatingRequest($pro, $link));
        } elseif ($ref->client_phone !== null && $ref->client_confirmed_at !== null && $ref->group->industry->client_texting_approved) {
            app(\App\Support\Sms\SmsSender::class)->send($ref->client_phone, "How was your experience with {$pro}? One quick rating: {$link} Reply STOP to opt out.");
        }
    }

    private function mustBeSender(Referral $ref, User $user): void
    {
        $team = Team::of($user);
        abort_unless($team !== null && $team->id === $ref->sender_team_id && in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);
    }

    /** @param array<string, mixed> $meta */
    private function event(Referral $ref, string $event, ?int $teamId, ?User $actor, array $meta = []): void
    {
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => $event, 'team_id' => $teamId, 'actor_id' => $actor?->id, 'meta' => $meta === [] ? null : json_encode($meta), 'created_at' => now()]);
    }
}
