<?php

declare(strict_types=1);

namespace App\Domains\Content\Demo\Http;

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Identity\Models\User;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The live demo (Sprint 52a). On a separate demo install (DEMO_INSTALL=true), visitors enter with one click as a
 * team or a network owner; everything resets every hour and nothing is ever sent. On the real site, /demo points to
 * the demo install when one is set in Admin → System.
 */
final class DemoController
{
    public function show(): Response|RedirectResponse
    {
        if (! config('app.demo_install')) {
            $url = (string) app(Settings::class)->get('demo.url', '');
            abort_if($url === '', 404);

            return redirect()->away($url);
        }

        return Inertia::render('Demo', [
            'resetsAt' => now()->addHour()->startOfHour()->toIso8601String(),
            'seo' => (new SeoData(title: 'Live demo', description: 'Explore Team to Team Referrals with sample teams and referrals. No sign-up; it resets every hour.', path: '/demo', index: false))->toArray(),
        ]);
    }

    public function enter(Request $request): RedirectResponse
    {
        abort_unless((bool) config('app.demo_install'), 404);
        $as = $request->validate(['as' => ['required', 'in:team,owner']])['as'];
        if (! DemoWorkspace::exists()) {
            try {
                DemoWorkspace::create(true);
            } catch (RuntimeException $e) {
                report($e);
                abort(503, 'The demo is being reset. Please try again in a minute.');
            }
        }
        // demo1 runs the networks; demo2 leads a team in one of them.
        $user = User::query()->where('email', ($as === 'owner' ? 'demo1@' : 'demo2@').DemoWorkspace::DOMAIN)->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect($as === 'owner' ? '/app' : '/app')->with('status', 'demo-welcome');
    }
}
