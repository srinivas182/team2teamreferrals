<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Territory;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The API sandbox (Sprint 44a). Test tokens act as the team's "sandbox twin", a sample team in a private sandbox
 * network for its industry, alongside three sample teams that hold seats in large cities. Referrals there behave
 * exactly like real ones, but every sandbox person has an address that never receives email or texts, client
 * messages are never sent, and nothing appears in the public directory, maps or sitemaps.
 */
final class Sandbox
{
    /** Sample teams that hold seats in every sandbox network (they accept, decline and update on request). */
    public const SAMPLE_TEAMS = ['Sample Team Alpha', 'Sample Team Beta', 'Sample Team Gamma'];

    /** The sandbox twin of a team (created the first time). */
    public static function twin(Team $team): Team
    {
        $twin = Team::query()->where('sandbox_of_team_id', $team->id)->first();
        if ($twin !== null) {
            return $twin;
        }

        return DB::transaction(function () use ($team) {
            $group = self::network(self::industryFor($team));
            $twin = self::sampleTeam($team->name.' (sandbox)', 'twin-'.$team->id, $team->id);
            self::giveSeat($group, $twin);

            return $twin;
        });
    }

    /** The private sandbox network for an industry, with its sample teams (created the first time). */
    public static function network(Industry $industry): Group
    {
        $slug = 'api-sandbox-'.$industry->slug;
        $group = Group::query()->where('slug', $slug)->first();
        if ($group !== null) {
            return $group;
        }
        $group = new Group(['industry_id' => $industry->id, 'slug' => $slug, 'name' => 'API sandbox: '.$industry->name, 'status' => 'live', 'is_private' => true,
            'visibility' => 'invite_only', 'description' => 'A private test network for API test tokens. Sample teams only.',
            'rules' => ['training_required' => false, 'auto_approve' => false], 'pricing' => []]);
        $group->forceFill(['is_sandbox' => true])->saveQuietly();
        foreach (self::SAMPLE_TEAMS as $i => $name) {
            self::giveSeat($group, self::sampleTeam($name.' ('.$industry->name.')', 'sample-'.$industry->slug.'-'.$i, null));
        }

        return $group;
    }

    /** The sandbox network's seats: territory, city to send to, and the sample team holding it. */
    /** @return list<array{territory: string, city_id: int, team: string, yours: bool}> */
    public static function seats(Group $group): array
    {
        $out = [];
        foreach (DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('seats.group_id', $group->id)->orderBy('seats.id')
            ->get(['seats.territory_name', 'seats.territory_type', 'seats.territory_id', 'teams.name', 'teams.sandbox_of_team_id']) as $s) {
            $out[] = ['territory' => (string) $s->territory_name, 'city_id' => self::cityOf((string) $s->territory_type, (int) $s->territory_id)->id,
                'team' => (string) $s->name, 'yours' => $s->sandbox_of_team_id !== null];
        }

        return $out;
    }

    /**
     * Test helper: a sample team sends the twin a referral, so the receiving side can be tried.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function incoming(Team $twin, array $answers = []): Referral
    {
        $seat = DB::table('seats')->where('primary_team_id', $twin->id)->firstOrFail();
        $group = Group::query()->findOrFail((int) $seat->group_id);
        $from = Team::query()->whereKey(DB::table('seats')->where('group_id', $group->id)->where('primary_team_id', '!=', $twin->id)->value('primary_team_id'))->firstOrFail();
        $city = self::cityOf((string) $seat->territory_type, (int) $seat->territory_id);
        $form = app(\App\Domains\Network\Industries\Templates\IndustryTemplates::class)->resolve('form', $group->industry, $group->id)['body'];
        $sample = [];
        foreach ($form as $f) {
            $sample[(string) $f['key']] = match ($f['type'] ?? 'text') {
                'boolean' => true, 'choice' => ($f['options'][0] ?? 'Yes'), 'multi_choice' => [($f['options'][0] ?? 'Yes')],
                default => match ((string) $f['key']) {
                    'client_name' => 'Sample Client', 'client_phone' => '+15555550100', 'client_email' => 'client@sandbox.invalid', default => 'Sample answer',
                },
            };
        }
        $r = app(Referrals::class)->send($from->leader, $group, $city, [...$sample, ...$answers], true, 'Sent by a sandbox sample team.', true);

        return $r['referral'] ?? throw new RuntimeException('The sandbox could not create a referral.');
    }

    public static function industryFor(Team $team): Industry
    {
        $id = DB::table('seat_applications')->join('groups', 'groups.id', '=', 'seat_applications.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->where(['seat_applications.team_id' => $team->id, 'seat_applications.status' => 'approved', 'groups.is_sandbox' => false])
            ->where('industries.tier', '!=', Tier::Directory->value)->value('industries.id');

        return (is_numeric($id) ? Industry::query()->find((int) $id) : null) ?? Industry::query()->where('slug', 'real-estate')->first() ?? Industry::query()->where('tier', '!=', Tier::Directory->value)->firstOrFail();
    }

    private static function sampleTeam(string $name, string $key, ?int $twinOf): Team
    {
        $user = User::query()->firstOrCreate(['email' => $key.'@sandbox.invalid'], ['first_name' => 'Sandbox', 'last_name' => Str::limit($name, 40, ''), 'password' => bcrypt(Str::random(40))]);
        $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->saveQuietly();
        $team = Team::forLeader($user);
        $team->forceFill(['name' => $name, 'sandbox_of_team_id' => $twinOf, 'profile_public' => false])->saveQuietly();

        return $team;
    }

    /** A seat in the largest enabled city not yet taken in the sandbox network, approved straight away. */
    private static function giveSeat(Group $group, Team $team): void
    {
        foreach (City::query()->where('is_enabled', true)->orderByDesc('population')->limit(200)->get() as $city) {
            try {
                $t = Territory::fromCity($group->industry->territory_unit, $city);
            } catch (Throwable) {
                continue;
            }
            if (DB::table('seats')->where(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']])->exists()) {
                continue;
            }
            $seat = DB::table('seats')->insertGetId([...$t, 'group_id' => $group->id, 'status' => 'active', 'primary_team_id' => $team->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seat_applications')->insert(['group_id' => $group->id, 'team_id' => $team->id, 'seat_id' => $seat, 'license_number' => null, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
    }

    private static function cityOf(string $type, int $id): City
    {
        $main = \App\Domains\Network\Directory\DirectoryUrls::mainCity($type, $id);

        return City::query()->findOrFail($main !== null ? (int) $main->id : 0);
    }
}
