<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;

/** Public team profiles: teams holding a seat that haven't hidden their profile. */
final class ProfileSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'profiles';
    }

    public function urls(): iterable
    {
        foreach (PublicProfiles::indexableQuery()->orderBy('teams.id')->limit(40000)->get(['teams.slug', 'teams.updated_at']) as $t) {
            yield new SitemapUrl('/t/'.$t->slug, substr((string) $t->updated_at, 0, 10), 'weekly', 0.6);
        }
    }
}
