<?php

declare(strict_types=1);

namespace App\Domains\Growth\Http;

use App\Domains\Growth\Activation;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Growth → Activation: the funnel from sign-up to first accepted referral, and the teams stuck on a step. */
final class ActivationController
{
    public const PERIODS = [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'];

    public function __invoke(Request $request): Response
    {
        $days = (int) $request->query('days', '90');
        $days = array_key_exists($days, self::PERIODS) ? $days : 90;
        $group = $request->query('group') !== null ? (int) $request->query('group') : null;
        $teams = Activation::teams($days, $group);

        return Inertia::render('growth/Activation', [
            'funnel' => Activation::funnel($teams),
            'stuck' => array_slice(Activation::stuck($teams), 0, 100),
            'total' => count($teams),
            'days' => $days,
            'group' => $group,
            'periods' => collect(self::PERIODS)->map(fn ($label, $d) => ['days' => $d, 'label' => $label])->values(),
            'groups' => DB::table('groups')->where('status', 'live')->orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->values(),
            'stuckAfter' => Activation::STUCK_AFTER_DAYS,
            'seo' => SeoData::private('Activation')->toArray(),
        ]);
    }
}
