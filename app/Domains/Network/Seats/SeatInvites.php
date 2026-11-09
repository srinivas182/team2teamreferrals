<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Invite teams to specific territories, one or many at once. Accepting runs through the normal seat rules. */
final class SeatInvites
{
    public const DAYS = 14;

    public function __construct(private readonly Seats $seats) {}

    /**
     * @param  list<array{email: string, city_id: int}>  $rows
     * @return array{sent: int, skipped: list<string>}
     */
    public function send(Group $group, array $rows, User $inviter): array
    {
        $sent = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $email = strtolower(trim($row['email']));
            $city = City::query()->find($row['city_id']);
            if (! $city instanceof City || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = "{$email}: invalid email or city";

                continue;
            }
            $territory = Territory::fromCity($group->industry->territory_unit, $city);
            $seat = Seat::query()->firstOrCreate(['group_id' => $group->id, 'territory_type' => $territory['territory_type'], 'territory_id' => $territory['territory_id']], $territory);
            if (DB::table('seat_invites')->where(['seat_id' => $seat->id, 'email' => $email, 'status' => 'pending'])->where('expires_at', '>', now())->exists()) {
                $skipped[] = "{$email}: already invited to {$seat->territory_name}";

                continue;
            }
            $token = Str::random(48);
            DB::table('seat_invites')->insert(['group_id' => $group->id, 'seat_id' => $seat->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::DAYS), 'invited_by' => $inviter->id, 'created_at' => now(), 'updated_at' => now()]);
            Notification::route('mail', $email)->notify(new SeatInviteNotification($group->name, $seat->territory_name, url('/invites/'.$token), $inviter->name));
            DB::table('seat_events')->insert(['seat_id' => $seat->id, 'event' => 'invited', 'meta' => json_encode(['email' => $email]), 'actor_id' => $inviter->id, 'created_at' => now()]);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** @return ?stdClass the pending, unexpired invite for this token */
    public function find(string $token): ?stdClass
    {
        /** @var ?stdClass $row */
        $row = DB::table('seat_invites')->where('token_hash', hash('sha256', $token))->where('status', 'pending')->where('expires_at', '>', now())->first();

        return $row;
    }

    /** @return string primary, backup or waitlist */
    public function accept(string $token, User $user): string
    {
        $invite = $this->find($token);
        if ($invite === null) {
            throw ValidationException::withMessages(['invite' => 'This invitation has expired or was already used.']);
        }
        if (strtolower($user->email) !== strtolower((string) $invite->email)) {
            throw ValidationException::withMessages(['invite' => 'This invitation was sent to '.$invite->email.'. Sign in with that email to accept it.']);
        }
        $team = Team::forLeader($user);
        $appId = (int) DB::table('seat_applications')->insertGetId(['group_id' => $invite->group_id, 'team_id' => $team->id, 'seat_id' => $invite->seat_id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $outcome = $this->seats->approve($appId, $invite->invited_by ? User::query()->find((int) $invite->invited_by) : null);
        DB::table('seat_invites')->where('id', $invite->id)->update(['status' => 'accepted', 'accepted_team_id' => $team->id, 'updated_at' => now()]);

        return $outcome;
    }
}
