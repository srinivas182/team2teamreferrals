<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/** Public team profiles: teams holding a seat that haven't hidden their profile. */
final class ProfileSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'profiles';
    }

    public function urls(): iterable
    {
        foreach (DB::table('teams')->where('profile_public', true)->whereNotNull('slug')->whereIn('id', DB::table('seats')->whereNotNull('primary_team_id')->select('primary_team_id'))->orderBy('id')->limit(40000)->get(['slug', 'updated_at']) as $t) {
            yield new SitemapUrl('/t/'.$t->slug, substr((string) $t->updated_at, 0, 10), 'weekly', 0.6);
        }
    }
}
