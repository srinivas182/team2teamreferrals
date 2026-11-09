<?php

declare(strict_types=1);

namespace App\Domains\Platform\Cdn;

use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

/** When public pages change, purge them from the CDN so visitors see the update within seconds. Cloudflare first. */
final class PurgeCdn implements ShouldQueue
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(PublicUrlsChanged $event): void
    {
        if ($this->settings->get('cdn.driver') !== 'cloudflare' || $event->paths === []) {
            return;
        }
        $base = rtrim((string) (config('seo.canonical_url') ?: config('app.url')), '/');
        $urls = array_map(fn (string $p) => $base.'/'.ltrim($p, '/'), $event->paths);
        $zone = (string) $this->settings->get('cdn.cloudflare.zone_id');
        // Cloudflare accepts up to 30 URLs per purge call.
        foreach (array_chunk($urls, 30) as $chunk) {
            Http::withToken((string) $this->settings->get('cdn.cloudflare.api_token'))->timeout(10)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", ['files' => $chunk])->throw();
        }
    }
}
