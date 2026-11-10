<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Territory;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Pre-launch reservations. A visitor reserves an industry and city with no card; the email link confirms it;
 * after creating an account they claim it, which files a founding application in the industry's launch group.
 * Licenses are collected and verified before launch, not at reservation time.
 */
final class Reservations
{
    public function launchAt(): string
    {
        return (string) app(Settings::class)->get('prelaunch.launch_at', '2027-03-01T15:00:00Z');
    }

    /** @return array{open: bool, territory: string, reason: ?string} */
    public function availability(Industry $industry, City $city): array
    {
        $group = $this->launchGroup($industry);
        $t = Territory::fromCity($industry->territory_unit, $city);
        $seat = Seat::query()->where(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']])->first();
        // Taken if someone holds the seat, or a confirmed reservation already covers this territory
        // (another city in the same county, metro or state counts for those industries).
        $reserved = DB::table('reservations')->where('group_id', $group->id)->whereIn('status', ['confirmed', 'claimed'])->pluck('city_id')
            ->contains(fn ($id) => ($c = City::query()->find((int) $id)) !== null && Territory::fromCity($industry->territory_unit, $c)['territory_id'] === $t['territory_id']);
        $taken = ($seat !== null && $seat->primary_team_id !== null) || $reserved;

        return ['open' => ! $taken, 'territory' => $t['territory_name'], 'reason' => $taken ? 'Already reserved. Reserve anyway to back it up or join the waitlist.' : null];
    }

    public function reserve(Industry $industry, City $city, string $email, string $name, ?string $business, bool $onWall, ?string $ref = null): void
    {
        $group = $this->launchGroup($industry);
        $territory = Territory::fromCity($industry->territory_unit, $city)['territory_name']; // also refuses switched-off places
        $email = strtolower(trim($email));
        if (DB::table('reservations')->where(['group_id' => $group->id, 'city_id' => $city->id, 'email' => $email])->exists()) {
            throw ValidationException::withMessages(['email' => 'You’ve already reserved this city. Check your email for the confirmation link.']);
        }
        $token = Str::random(48);
        $referrer = $ref ? DB::table('reservations')->where('share_id', $ref)->whereIn('status', ['confirmed', 'claimed'])->value('id') : null;
        DB::table('reservations')->insert(['share_id' => (string) Str::ulid(), 'referred_by' => $referrer, 'group_id' => $group->id, 'city_id' => $city->id, 'email' => $email, 'name' => $name, 'business' => $business, 'on_wall' => $onWall,
            'token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]);
        Notification::route('mail', $email)->notify(new ConfirmReservationNotification($territory, $group->name, url('/reserve/confirm/'.$token)));
    }

    /** @return ?stdClass the reservation, now confirmed */
    public function confirm(string $token): ?stdClass
    {
        $r = $this->find($token);
        if ($r !== null && $r->status === 'pending') {
            DB::table('reservations')->where('id', $r->id)->update(['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);
            $r->status = 'confirmed';
            if ($r->share_id) {
                GenerateShareImages::dispatch((string) $r->share_id);
            }
        }

        return $r;
    }

    /** Turn a confirmed reservation into a founding application in the launch group (as the signed-in user). */
    public function claim(string $token, User $user): void
    {
        $r = $this->find($token);
        if ($r === null || $r->status !== 'confirmed') {
            throw ValidationException::withMessages(['reservation' => 'This reservation can’t be claimed (not confirmed, or already claimed).']);
        }
        if (strtolower($user->email) !== strtolower((string) $r->email)) {
            throw ValidationException::withMessages(['reservation' => 'This reservation was made with '.$r->email.'. Sign in with that email to claim it.']);
        }
        DB::transaction(function () use ($r, $user) {
            $group = Group::query()->findOrFail((int) $r->group_id);
            $city = City::query()->findOrFail((int) $r->city_id);
            $team = Team::of($user) ?? Team::forLeader($user);
            if ($r->business && $team->name === $user->name) {
                $team->forceFill(['name' => $r->business])->save();
            }
            $t = Territory::fromCity($group->industry->territory_unit, $city);
            $seat = Seat::query()->firstOrCreate(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']], $t);
            $appId = DB::table('seat_applications')->where(['seat_id' => $seat->id, 'team_id' => $team->id])->value('id')
                ?? DB::table('seat_applications')->insertGetId(['group_id' => $group->id, 'team_id' => $team->id, 'seat_id' => $seat->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seat_events')->insert(['seat_id' => $seat->id, 'team_id' => $team->id, 'event' => 'reserved', 'meta' => json_encode(['reservation' => $r->id]), 'actor_id' => $user->id, 'created_at' => now()]);
            DB::table('reservations')->where('id', $r->id)->update(['status' => 'claimed', 'application_id' => (int) $appId, 'updated_at' => now()]);
        });
    }

    /** @return array<int, array{lat: float, lon: float, industry: string}> confirmed reservations for the live map */
    public function points(): array
    {
        return DB::table('reservations')->join('cities', 'cities.id', '=', 'reservations.city_id')->join('groups', 'groups.id', '=', 'reservations.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->whereIn('reservations.status', ['confirmed', 'claimed'])->whereNotNull('cities.lat')->limit(5000)
            ->get(['cities.lat', 'cities.lon', 'industries.slug'])->map(fn ($r) => ['lat' => (float) $r->lat, 'lon' => (float) $r->lon, 'industry' => (string) $r->slug])->values()->all();
    }

    /** @return array<int, array{state: string, name: string, count: int}> the states with the most reservations */
    public function race(int $limit = 8): array
    {
        return DB::table('reservations')->join('cities', 'cities.id', '=', 'reservations.city_id')->join('states', 'states.id', '=', 'cities.state_id')
            ->whereIn('reservations.status', ['confirmed', 'claimed'])->groupBy('states.code', 'states.name')->selectRaw('states.code, states.name, count(*) as n')->orderByDesc('n')->limit($limit)->get()
            ->map(fn ($r) => ['state' => (string) $r->code, 'name' => (string) $r->name, 'count' => (int) $r->n])->values()->all();
    }

    /** @return ?array{business: string, territory: string, industry: string, group: string, shareId: string, invited: int} */
    public function shared(string $shareId): ?array
    {
        $r = DB::table('reservations')->join('groups', 'groups.id', '=', 'reservations.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->where('reservations.share_id', $shareId)->whereIn('reservations.status', ['confirmed', 'claimed'])
            ->first(['reservations.id', 'reservations.business', 'reservations.name', 'reservations.city_id', 'groups.name as group', 'industries.name as industry', 'industries.territory_unit']);
        if ($r === null) {
            return null;
        }
        $city = City::query()->find((int) $r->city_id);

        return ['business' => (string) ($r->business ?: Str::before((string) $r->name, ' ')), 'territory' => $city ? Territory::fromCity((string) $r->territory_unit, $city)['territory_name'] : '',
            'industry' => (string) $r->industry, 'group' => (string) $r->group, 'shareId' => $shareId, 'invited' => DB::table('reservations')->where('referred_by', $r->id)->whereIn('status', ['confirmed', 'claimed'])->count()];
    }

    /** @return array{reservations: int, cities: int, states: int} */
    public function counters(): array
    {
        $q = DB::table('reservations')->join('cities', 'cities.id', '=', 'reservations.city_id')->whereIn('reservations.status', ['confirmed', 'claimed']);

        return ['reservations' => (clone $q)->count(), 'cities' => (clone $q)->distinct()->count('reservations.city_id'), 'states' => (clone $q)->distinct()->count('cities.state_id')];
    }

    /** @return array<int, array{name: string, city: string, industry: string}> newest founding members who agreed to be shown */
    public function wall(int $limit = 24): array
    {
        return DB::table('reservations')->join('cities', 'cities.id', '=', 'reservations.city_id')->join('states', 'states.id', '=', 'cities.state_id')
            ->join('groups', 'groups.id', '=', 'reservations.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->whereIn('reservations.status', ['confirmed', 'claimed'])->where('reservations.on_wall', true)->orderByDesc('reservations.confirmed_at')->limit($limit)
            ->get(['reservations.business', 'reservations.name', 'cities.name as city', 'states.code', 'industries.name as industry'])
            // First name only for people without a business name: the wall is public.
            ->map(fn ($r) => ['name' => (string) ($r->business ?: Str::before((string) $r->name, ' ')), 'city' => "{$r->city}, {$r->code}", 'industry' => (string) $r->industry])->values()->all();
    }

    private function find(string $token): ?stdClass
    {
        /** @var ?stdClass $r */
        $r = DB::table('reservations')->where('token_hash', hash('sha256', $token))->first();

        return $r;
    }

    private function launchGroup(Industry $industry): Group
    {
        $g = Group::query()->where(['industry_id' => $industry->id, 'is_launch' => true])->first();
        if ($g === null || $g->status === 'closed') {
            throw ValidationException::withMessages(['industry' => 'Reservations aren’t open for this industry yet.']);
        }

        return $g;
    }
}
