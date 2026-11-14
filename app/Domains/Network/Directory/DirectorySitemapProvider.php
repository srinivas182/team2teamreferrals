<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/**
 * Directory pages that are worth indexing: the directory, every public group, industry pages for states with a
 * filled seat, and city pages whose seat is filled or waitlisted in the largest metro areas.
 */
final class DirectorySitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'directory';
    }

    public function urls(): iterable
    {
        $groups = Directory::groups();
        if ($groups->isEmpty()) {
            return;
        }
        $today = now()->toDateString();
        yield new SitemapUrl('/groups', $today, 'daily', 0.8);
        foreach ($groups as $g) {
            yield new SitemapUrl('/groups/'.$g->slug, substr((string) $g->updated_at, 0, 10) ?: $today, 'daily', 0.7);
        }
        $waitlisted = DB::table('seat_waitlist')->distinct()->pluck('seat_id')->flip();
        $seats = DB::table('seats')->whereIn('group_id', $groups->pluck('id'))->whereNotNull('state_code')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('status', Directory::FILLED)->whereNotNull('primary_team_id'))->orWhereIn('id', $waitlisted->keys()))
            ->get(['id', 'group_id', 'status', 'primary_team_id', 'territory_type', 'territory_id', 'state_code', 'updated_at']);
        $industry = $groups->mapWithKeys(fn ($g) => [$g->id => $g->industry->slug]);
        $top = Directory::topMetros();
        $seen = [];
        foreach ($seats as $s) {
            $state = '/industries/'.$industry[$s->group_id].'/'.strtolower((string) $s->state_code);
            $filled = in_array($s->status, Directory::FILLED, true) && $s->primary_team_id !== null;
            if ($filled && ! isset($seen[$state])) {
                $seen[$state] = true;
                yield new SitemapUrl($state, substr((string) $s->updated_at, 0, 10), 'weekly', 0.6);
            }
            $city = DirectoryUrls::mainCity((string) $s->territory_type, (int) $s->territory_id);
            $metro = $city?->county_id ? DB::table('county_metro')->where('county_id', $city->county_id)->value('metro_id') : null;
            if ($city !== null && $metro !== null && isset($top[(int) $metro]) && ! isset($seen[$state.'/'.$city->slug])) {
                $seen[$state.'/'.$city->slug] = true;
                yield new SitemapUrl($state.'/'.$city->slug, substr((string) $s->updated_at, 0, 10), 'weekly', 0.6);
            }
        }
    }
}
