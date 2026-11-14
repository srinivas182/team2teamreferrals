<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * The network map's data and its embeddable page. No sessions or cookies; cached for at most 30 seconds at the
 * edge, so a seat change shows within a minute. The same facts are on the group and industry pages as text.
 */
final class MapController
{
    public function group(string $slug): JsonResponse
    {
        return $this->json(NetworkMap::forGroup($this->listedGroup($slug)));
    }

    public function industry(string $slug): JsonResponse
    {
        return $this->json(NetworkMap::forIndustry(Industry::query()->where('slug', $slug)->firstOrFail()));
    }

    /** A map anyone can embed (it shows public facts only); every link opens on our site. */
    public function embed(string $kind, string $slug): Response
    {
        $data = $kind === 'group' ? NetworkMap::forGroup($this->listedGroup($slug)) : NetworkMap::forIndustry(Industry::query()->where('slug', $slug)->firstOrFail());
        $nonce = Vite::cspNonce();
        $csp = implode('; ', ["default-src 'self'", "script-src 'self' 'nonce-{$nonce}'", "style-src 'self' 'unsafe-inline'", "img-src 'self' data:", "connect-src 'self'",
            "font-src 'self' data:", "base-uri 'none'", "form-action 'none'", 'frame-ancestors *']);

        return response()->view('embed-map', ['data' => $data, 'src' => "/map/{$kind}/{$slug}.json", 'page' => url($kind === 'group' ? '/groups/'.$slug : '/industries/'.$slug),
            'site' => (string) config('seo.site_name')], 200, ['Content-Security-Policy' => $csp, 'Cache-Control' => 'public, max-age=30, s-maxage=30', 'X-Robots-Tag' => 'noindex']);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=30, s-maxage=30', 'X-Robots-Tag' => 'noindex']);
    }

    private function listedGroup(string $slug): Group
    {
        return Group::query()->listed()->with('industry')->where('slug', $slug)->firstOrFail();
    }
}
