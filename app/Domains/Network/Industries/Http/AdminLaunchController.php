<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Network\Industries\Catalogue\Readiness;
use App\Domains\Seo\SeoData;
use Inertia\Inertia;
use Inertia\Response;

final class AdminLaunchController
{
    public function __invoke(Readiness $readiness): Response
    {
        $board = $readiness->board();

        return Inertia::render('admin/industries/Launch', [
            'board' => $board,
            'summary' => ['ready' => count(array_filter($board, fn ($r) => $r['ready'])), 'total' => count($board)],
            'seo' => SeoData::private('Launch readiness')->toArray(),
        ]);
    }
}
