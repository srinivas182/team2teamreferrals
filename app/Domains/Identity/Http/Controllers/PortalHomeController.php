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

        return Inertia::render('app/Home', [
            'checklist' => [
                ['label' => 'Verify your email', 'done' => $user->hasVerifiedEmail()],
                ['label' => 'Verify your mobile', 'done' => $user->hasVerifiedPhone()],
                ['label' => 'Turn on two-step sign-in or add a passkey', 'done' => $user->hasTwoFactorEnabled() || $user->passkeys()->exists()],
                ['label' => 'Add your license (arrives with onboarding)', 'done' => false],
            ],
            'seo' => SeoData::private('Home')->toArray(),
        ]);
    }
}
