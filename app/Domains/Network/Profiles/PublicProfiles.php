<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Network\Seats\Models\Team;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public "refer to me" profiles: a web address per team, QR codes, and simple daily counts for the team. */
final class PublicProfiles
{
    public static function ensureSlug(Team $team): string
    {
        if ($team->slug) {
            return $team->slug;
        }
        $team->forceFill(['slug' => self::uniqueSlug($team->name, $team->id)])->saveQuietly();

        return (string) $team->slug;
    }

    /** "Hurricane Sun" → hurricane-sun (or hurricane-sun-2 when taken). */
    public static function uniqueSlug(string $name, ?int $exceptId): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = $base;
        for ($i = 2; Team::query()->where('slug', $slug)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function url(Team $team, ?string $source = null): string
    {
        return url('/t/'.self::ensureSlug($team)).($source ? '?src='.$source : '');
    }

    /** The QR code for the profile (scans are counted separately from link clicks). */
    public static function qrSvg(Team $team, int $size = 320): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString(self::url($team, 'qr'));
    }

    public static function count(Team $team, string $kind): void
    {
        $day = now()->toDateString();
        DB::table('profile_stats')->insertOrIgnore(['team_id' => $team->id, 'day' => $day, 'kind' => $kind, 'count' => 0]);
        DB::table('profile_stats')->where(['team_id' => $team->id, 'day' => $day, 'kind' => $kind])->increment('count');
    }

    /** @return array<string, int> totals for the last 30 days */
    public static function last30(Team $team): array
    {
        $rows = DB::table('profile_stats')->where('team_id', $team->id)->where('day', '>=', now()->subDays(29)->toDateString())->groupBy('kind')->selectRaw('kind, sum(count) as n')->pluck('n', 'kind');

        return ['view' => (int) ($rows['view'] ?? 0), 'qr' => (int) ($rows['qr'] ?? 0), 'link' => (int) ($rows['link'] ?? 0), 'referral' => (int) ($rows['referral'] ?? 0)];
    }

    /** Teams whose profile search engines should list: public, holding a filled seat in a launched public network. */
    public static function indexableQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('teams')->where('teams.profile_public', true)->whereNotNull('teams.slug')
            ->whereExists(fn ($q) => $q->from('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->whereColumn('seats.primary_team_id', 'teams.id')
                ->whereIn('seats.status', \App\Domains\Network\Directory\Directory::FILLED)->where(['groups.status' => 'live', 'groups.is_private' => false]));
    }

    public static function indexable(Team $team): bool
    {
        return self::indexableQuery()->where('teams.id', $team->id)->exists();
    }
}
