<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use App\Domains\Platform\Modules\Modules;
use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/** The reserve page and founding members' share pages (only those who agreed to be shown publicly). */
final class PrelaunchSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'reserve';
    }

    public function urls(): iterable
    {
        if (! app(Modules::class)->enabled('prelaunch')) {
            return;
        }
        yield new SitemapUrl('/reserve', now()->toDateString(), 'daily', 0.9);
        foreach (DB::table('reservations')->whereIn('status', ['confirmed', 'claimed'])->where('on_wall', true)->whereNotNull('share_id')->orderByDesc('id')->limit(40000)->get(['share_id', 'updated_at']) as $r) {
            yield new SitemapUrl('/reserve/c/'.$r->share_id, substr((string) $r->updated_at, 0, 10), 'monthly', 0.5);
        }
    }
}
