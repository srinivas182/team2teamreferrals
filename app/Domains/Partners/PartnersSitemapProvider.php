<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/** Partner pages worth indexing: the hub, each category in a city with someone listed, and every approved partner. */
final class PartnersSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'partners';
    }

    public function urls(): iterable
    {
        if (! Partners::enabled()) {
            return;
        }
        $live = DB::table('partner_listings as l')->join('partners as p', 'p.id', '=', 'l.partner_id')->join('partner_categories as k', 'k.id', '=', 'l.category_id')
            ->join('cities as c', 'c.id', '=', 'l.city_id')->join('states as s', 's.id', '=', 'c.state_id')
            ->where('p.status', 'approved')->whereIn('p.billing_status', Partner::LIVE_BILLING)->where('l.status', 'active')->where('k.is_active', true)->where('c.is_enabled', true);
        $places = (clone $live)->groupBy('k.slug', 's.code', 'c.slug')->selectRaw('k.slug as category, s.code as state, c.slug as city, max(p.updated_at) as updated')->get();
        if ($places->isEmpty()) {
            return;
        }
        yield new SitemapUrl('/partners', now()->toDateString(), 'weekly', 0.5);
        foreach ($places as $r) {
            yield new SitemapUrl('/partners/'.$r->category.'/'.strtolower((string) $r->state).'/'.$r->city, substr((string) $r->updated, 0, 10), 'weekly', 0.5);
        }
        foreach ((clone $live)->groupBy('p.slug')->selectRaw('p.slug, max(p.updated_at) as updated')->get() as $r) {
            yield new SitemapUrl('/partner/'.$r->slug, substr((string) $r->updated, 0, 10), 'monthly', 0.4);
        }
    }
}
