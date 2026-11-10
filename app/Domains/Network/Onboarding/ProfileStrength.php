<?php

declare(strict_types=1);

namespace App\Domains\Network\Onboarding;

use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/** How complete and trustworthy a team's profile is, out of 100. Feeds waitlist ranking until the trust score (Sprint 34). */
final class ProfileStrength
{
    /** @return array{score: int, items: list<array{label: string, points: int, done: bool}>} */
    public static function for(Team $team): array
    {
        $licenses = DB::table('team_licenses')->where('team_id', $team->id)->pluck('status');
        $items = [
            ['label' => 'Give your team its own name', 'points' => 10, 'done' => $team->name !== $team->leader->name],
            ['label' => 'Add a logo', 'points' => 10, 'done' => $team->logo_path !== null],
            ['label' => 'Write a short “about” (80+ characters)', 'points' => 15, 'done' => mb_strlen((string) $team->bio) >= 80],
            ['label' => 'Say which areas you serve', 'points' => 10, 'done' => trim((string) $team->service_area) !== ''],
            ['label' => 'Add a website or phone', 'points' => 10, 'done' => $team->website !== null || $team->phone !== null],
            ['label' => 'Link a social profile', 'points' => 5, 'done' => ! empty($team->social)],
            ['label' => 'Get a license verified', 'points' => 25, 'done' => $licenses->contains('verified')],
            ['label' => 'Invite a teammate', 'points' => 5, 'done' => DB::table('team_members')->where('team_id', $team->id)->count() > 1],
            ['label' => 'Everyone on the team passes the network training', 'points' => 10, 'done' => app(\App\Domains\Network\Training\Training::class)->teamTrained($team)],
        ];
        $score = array_sum(array_map(fn ($i) => $i['done'] ? $i['points'] : 0, $items));

        return ['score' => $score, 'items' => $items];
    }
}
