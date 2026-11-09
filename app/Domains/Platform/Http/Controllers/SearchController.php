<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Search\SearchRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchController
{
    public function __invoke(Request $request, SearchRegistry $registry): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        /** @var User $user */
        $user = $request->user();

        return response()->json(['groups' => mb_strlen($q) < 2 ? [] : $registry->search(mb_substr($q, 0, 80), $user)]);
    }
}
