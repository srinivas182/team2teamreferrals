<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Health\HealthChecks;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

final class StatusController
{
    /** For uptime monitors: 200 when everything is up, 503 otherwise. Booleans and timings only. */
    public function health(HealthChecks $checks): JsonResponse
    {
        $r = $checks->run();
        $ok = collect($r)->every(fn ($c) => $c['ok']);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $r, 'time' => now()->toIso8601String()], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    /** Public status page members can check during an incident. */
    public function page(HealthChecks $checks, Settings $settings): Response
    {
        $r = $checks->cached();

        return Inertia::render('Status', [
            'components' => collect(HealthChecks::COMPONENTS)->filter(fn ($label, $k) => isset($r[$k]))->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'ok' => $r[$k]['ok']])->values(),
            'incident' => $settings->get('status.incident'),
            'checkedAt' => now()->toIso8601String(),
            'seo' => (new SeoData(title: 'System status', description: 'Live status of Team to Team Referrals.', index: false))->toArray(),
        ]);
    }
}
