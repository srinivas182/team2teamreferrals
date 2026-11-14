<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles\Widgets;

use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Profiles\ProfileRecord;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\ProfileStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Embeddable widgets members put on their own websites: the "Refer a client to me" button, the verified-member
 * badge and the review widget. Each one is checked live when it loads: it shows only while the team keeps a public
 * profile and holds a filled seat (a lapsed seat makes it disappear), only on the team's own websites, and always
 * links back to the team's profile, where the same facts exist as text and structured data.
 */
final class Widgets
{
    public const TYPES = ['badge', 'reviews', 'button'];

    public const MAX_DOMAINS = 10;

    /** @return list<string> the hostnames a team's widgets may appear on (its listed websites, plus its profile website) */
    public static function domains(Team $team): array
    {
        $listed = $team->widget_domains ?? [];
        $site = $team->website ? self::host((string) $team->website) : null;

        return array_values(array_unique(array_filter([...array_map(fn ($d) => (string) $d, $listed), $site])));
    }

    /** "https://www.Example.com/about" → "example.com" (null when it isn't a real hostname) */
    public static function host(string $input): ?string
    {
        $in = strtolower(trim($input));
        $host = parse_url(str_contains($in, '://') ? $in : 'https://'.$in, PHP_URL_HOST);
        if (! is_string($host) || ! preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $host)) {
            return null;
        }

        return (string) preg_replace('/^www\./', '', $host);
    }

    /** @param list<string> $inputs */
    public static function setDomains(Team $team, array $inputs): void
    {
        $hosts = [];
        foreach (array_filter(array_map('trim', $inputs)) as $i) {
            $h = self::host($i) ?? throw ValidationException::withMessages(['domains' => "“{$i}” isn’t a website address. Use a form like example.com."]);
            $hosts[] = $h;
        }
        $hosts = array_values(array_unique($hosts));
        if (count($hosts) > self::MAX_DOMAINS) {
            throw ValidationException::withMessages(['domains' => 'Add up to '.self::MAX_DOMAINS.' websites.']);
        }
        $team->forceFill(['widget_domains' => $hosts])->save();
    }

    /** The CSP frame-ancestors that lets only the team's websites (and our own preview) show a widget. */
    public static function frameAncestors(Team $team): string
    {
        $sources = ["'self'"];
        foreach (self::domains($team) as $h) {
            $sources[] = 'https://'.$h;
            $sources[] = 'https://www.'.$h;
        }

        return 'frame-ancestors '.implode(' ', $sources);
    }

    /** Is this page address on one of the team's websites? (Used to count where widgets are seen.) */
    public static function allowedReferer(Team $team, ?string $referer): ?string
    {
        $host = $referer !== null ? self::host($referer) : null;

        return $host !== null && in_array($host, self::domains($team), true) ? $host : null;
    }

    /**
     * What a widget shows, checked live; null when the team no longer qualifies (private profile or no filled seat).
     *
     * @return array<string, mixed>|null
     */
    public static function facts(Team $team): ?array
    {
        if (! $team->profile_public) {
            return null;
        }
        $seat = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->where('seats.primary_team_id', $team->id)->whereIn('seats.status', Directory::FILLED)->where('groups.status', 'live')
            ->orderBy('seats.created_at')->first(['seats.territory_name', 'groups.name as group', 'industries.slug as industry', 'groups.industry_id']);
        if ($seat === null) {
            return null;
        }
        $industry = \App\Domains\Network\Industries\Models\Industry::query()->find((int) $seat->industry_id);
        $public = Directory::teams([$team->id], (int) $seat->industry_id)[$team->id] ?? null;
        $reviews = ProfileRecord::reviews($team, 3);

        return [
            'name' => $team->name, 'slug' => $team->slug, 'logo' => $team->logo_path ? Storage::disk('public')->url((string) $team->logo_path) : null,
            'pro' => $industry?->terms()['pro'] ?? 'pro', 'territory' => (string) $seat->territory_name, 'group' => (string) $seat->group,
            'licenses' => $public['licenses'] ?? [], 'trust' => $public['trust']['score'] ?? null,
            'rating' => $public['rating'] ?? null, 'ratings' => (int) ($public['ratings'] ?? 0), 'enoughRatings' => (int) ($public['ratings'] ?? 0) >= ProfileStats::MIN_RATINGS,
            'reviews' => array_values(array_filter($reviews['items'], fn ($r) => $r['comment'] !== null)), 'breakdown' => $reviews['breakdown'],
            'profile' => url('/t/'.$team->slug),
        ];
    }

    public static function count(Team $team, string $widget, string $kind, ?string $domain): void
    {
        $key = ['team_id' => $team->id, 'day' => now()->toDateString(), 'widget' => in_array($widget, self::TYPES, true) ? $widget : 'badge', 'kind' => $kind, 'domain' => (string) $domain];
        DB::table('widget_stats')->insertOrIgnore([...$key, 'count' => 0]);
        DB::table('widget_stats')->where($key)->increment('count');
    }

    /** @return array<string, array{view: int, click: int, referral: int}> last 30 days, by widget */
    public static function stats(Team $team): array
    {
        $rows = DB::table('widget_stats')->where('team_id', $team->id)->where('day', '>=', now()->subDays(29)->toDateString())
            ->groupBy('widget', 'kind')->selectRaw('widget, kind, sum(count) as n')->get();
        $out = [];
        foreach (self::TYPES as $w) {
            $out[$w] = ['view' => 0, 'click' => 0, 'referral' => 0];
        }
        foreach ($rows as $r) {
            if (isset($out[$r->widget][$r->kind])) {
                $out[$r->widget][$r->kind] = (int) $r->n;
            }
        }

        return $out;
    }
}
