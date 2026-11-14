<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Network\Profiles\Events\LeadVerified;
use App\Domains\Referrals\Events\ReferralEventRecorded;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The public API (Sprint 44): routes, the per-token rate limit by plan, and the developer pages. */
final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::aliasMiddleware('api.scope', Http\Middleware\RequireScope::class);
        // One bucket per token, sized by the team's plan; headers tell the caller what's left.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->attributes->get('api.token');

            return $token instanceof ApiToken ? Limit::perMinute(ApiAccess::perMinute($token))->by('api-token:'.$token->id) : Limit::perMinute(30)->by('api-ip:'.$request->ip());
        });
        // Outgoing webhooks hear about every referral timeline entry and every verified profile lead.
        Event::listen(ReferralEventRecorded::class, fn (ReferralEventRecorded $e) => Webhooks\Webhooks::referralEvent($e->referral, $e->event));
        Event::listen(LeadVerified::class, fn (LeadVerified $e) => Webhooks\Webhooks::leadReceived($e->leadId));
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadRoutesFrom(__DIR__.'/web.php');
        // Saved responses for safe retries are kept 24 hours.
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->call(fn () => \Illuminate\Support\Facades\DB::table('api_idempotency_keys')->where('created_at', '<', now()->subHours(Http\Middleware\Idempotency::HOURS))->delete())
                ->hourly()->name('api:idempotency-cleanup')->onOneServer();
            $s->call(fn () => Webhooks\Webhooks::retryDue())->everyMinute()->name('api:webhook-retries')->withoutOverlapping()->onOneServer();
            $s->call(fn () => Webhooks\Webhooks::prune())->dailyAt('04:20')->name('api:webhook-log-cleanup')->onOneServer();
        });
    }
}
