<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where sign-ups come from (Sprint 46b). On public pages the first visit is remembered (source, medium and campaign
 * from utm_ tags, else the referring site, else "direct") and so is the latest tagged visit; a click in a campaign
 * email remembers the campaign. At sign-up all of it is saved with the account. First-party cookies, 90 days.
 */
final class Attribution
{
    public const FIRST = 't2t_first';

    public const LAST = 't2t_last';

    public const CAMPAIGN = 't2t_cmp';

    public const DAYS = 90;

    /**
     * What a visit says (from the browser beacon on public pages: the path, the referring page and any utm_ tags).
     *
     * @param  array<string, mixed>  $utm
     * @return array{source: string, medium: ?string, campaign: ?string, landing: string, referrer: ?string, at: string}
     */
    public static function visit(string $path, ?string $referrer, array $utm, string $ownHost): array
    {
        $tag = fn (string $k) => isset($utm[$k]) && is_string($utm[$k]) && trim($utm[$k]) !== '' ? mb_substr(trim($utm[$k]), 0, 100) : null;
        $refHost = $referrer !== null && $referrer !== '' ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $external = $refHost !== '' && $refHost !== strtolower($ownHost) && ! str_ends_with($refHost, '.'.strtolower($ownHost));
        $source = $tag('source') ?? ($external ? $refHost : 'direct');

        return ['source' => $source, 'medium' => $tag('medium') ?? ($tag('source') === null && $external ? 'referral' : null), 'campaign' => $tag('campaign'),
            'landing' => mb_substr('/'.ltrim($path, '/'), 0, 255), 'referrer' => $external ? mb_substr($refHost, 0, 255) : null, 'at' => now()->toIso8601String()];
    }

    public static function record(User $user, Request $request): void
    {
        $first = self::cookie($request, self::FIRST);
        $last = self::cookie($request, self::LAST) ?? $first;
        $campaign = $request->cookie(self::CAMPAIGN);
        $campaignId = is_string($campaign) && ctype_digit($campaign) && DB::table('campaigns')->where('id', (int) $campaign)->exists() ? (int) $campaign : null;
        if ($first === null && $campaignId === null) {
            return;
        }
        DB::table('signup_attributions')->insertOrIgnore([
            'user_id' => $user->id, 'first_source' => $first['source'] ?? null, 'first_medium' => $first['medium'] ?? null, 'first_campaign' => $first['campaign'] ?? null,
            'first_landing' => $first['landing'] ?? null, 'first_referrer' => $first['referrer'] ?? null, 'first_at' => isset($first['at']) ? now()->parse($first['at']) : null,
            'last_source' => $last['source'] ?? null, 'last_medium' => $last['medium'] ?? null, 'last_campaign' => $last['campaign'] ?? null, 'last_landing' => $last['landing'] ?? null,
            'last_at' => isset($last['at']) ? now()->parse($last['at']) : null, 'campaign_id' => $campaignId, 'created_at' => now(),
        ]);
        $p = Prospect::query()->where('user_id', $user->id)->first();
        if ($p !== null && $first !== null) {
            Prospects::log($p, null, 'signup', 'Came from '.$first['source'].($first['campaign'] ? " ({$first['campaign']})" : '').($campaignId !== null ? '; last clicked a campaign email' : '').'.');
        }
    }

    /**
     * Sign-ups in a period by first-visit source, and how many of them joined.
     *
     * @return list<array{source: string, medium: ?string, campaign: ?string, signups: int, joined: int}>
     */
    public static function report(string $from, string $to, string $touch = 'first'): array
    {
        $q = DB::table('signup_attributions as a')->leftJoin('prospects as p', fn ($j) => $j->on('p.user_id', '=', 'a.user_id')->where('p.stage', 'joined'))
            ->whereBetween('a.created_at', [$from, $to]);
        $q = $touch === 'last'
            ? $q->groupBy('a.last_source', 'a.last_medium', 'a.last_campaign')->selectRaw('a.last_source as source, a.last_medium as medium, a.last_campaign as campaign, count(*) as signups, count(p.id) as joined')
            : $q->groupBy('a.first_source', 'a.first_medium', 'a.first_campaign')->selectRaw('a.first_source as source, a.first_medium as medium, a.first_campaign as campaign, count(*) as signups, count(p.id) as joined');

        return array_values($q->orderByDesc('signups')->limit(100)->get()
            ->map(fn ($r) => ['source' => (string) ($r->source ?? 'unknown'), 'medium' => $r->medium, 'campaign' => $r->campaign, 'signups' => (int) $r->signups, 'joined' => (int) $r->joined])->all());
    }

    /** @return array<string, string>|null */
    private static function cookie(Request $request, string $name): ?array
    {
        $raw = $request->cookie($name);
        $v = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($v) ? $v : null;
    }
}
