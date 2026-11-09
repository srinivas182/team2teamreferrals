<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** First-run dashboard. Becomes the full dashboard (Today view, KPIs) in the referral sprints. */
final class PortalHomeController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $team = \App\Domains\Network\Seats\Models\Team::of($user) ?? \App\Domains\Network\Seats\Models\Team::forLeader($user);
        $strength = \App\Domains\Network\Onboarding\ProfileStrength::for($team);
        $licenses = \Illuminate\Support\Facades\DB::table('team_licenses')->where('team_id', $team->id)->count();
        $applied = \Illuminate\Support\Facades\DB::table('seat_applications')->where('team_id', $team->id)->exists();
        $holds = \Illuminate\Support\Facades\DB::table('seats')->where('primary_team_id', $team->id)->exists();

        return Inertia::render('app/Home', [
            // From sign-up to first seat, in order. Each step links to where it's done.
            'checklist' => [
                ['label' => 'Verify your email', 'done' => $user->hasVerifiedEmail(), 'href' => null],
                ['label' => 'Verify your mobile', 'done' => $user->hasVerifiedPhone(), 'href' => '/verify-phone'],
                ['label' => 'Turn on two-step sign-in or add a passkey', 'done' => $user->hasTwoFactorEnabled() || $user->passkeys()->exists(), 'href' => '/app/settings/security'],
                ['label' => 'Complete your team profile', 'done' => $strength['score'] >= 50, 'href' => '/app/team'],
                ['label' => 'Add your license', 'done' => $licenses > 0, 'href' => '/app/profile'],
                ['label' => 'Apply for your city', 'done' => $applied, 'href' => '/app/groups'],
                ['label' => 'Hold your first seat', 'done' => $holds, 'href' => '/app/groups'],
            ],
            'strength' => $strength,
            'seo' => SeoData::private('Home')->toArray(),
        ]);
    }
}
