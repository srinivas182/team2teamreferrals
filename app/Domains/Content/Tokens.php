<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Billing\PricingRules;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Platform\Ai\AiCredits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures in website copy come from live settings, never typed in: {seat_price}, {trial_months} and the rest are filled
 * when a page is shown, so a price change updates the whole site (and its structured data and llms.txt). Industry and
 * city placeholders ({industry}, {pro}, {city}…) fill industry templates and city pages.
 */
final class Tokens
{
    /** @return array<string, string> */
    public static function site(): array
    {
        return Cache::remember('cms.tokens', 300, function (): array {
            $prices = DB::table('groups')->whereNotNull('pricing')->pluck('pricing')->map(fn ($p) => (array) json_decode((string) $p, true));
            $seat = $prices->pluck('seat_price')->filter(fn ($v) => is_numeric($v))->min();
            $trial = $prices->pluck('trial_months')->filter(fn ($v) => is_numeric($v))->max();
            $default = ['seat_price' => 10, 'trial_months' => 3, 'annual_months_free' => 2]; // as Group::defaultPricing

            return [
                'site_name' => (string) config('seo.site_name', 'Team to Team Referrals'),
                'seat_price' => '$'.(int) ($seat ?? $default['seat_price']),
                'trial_months' => (string) (int) ($trial ?? $default['trial_months']),
                'annual_months_free' => (string) (int) ($prices->pluck('annual_months_free')->filter(fn ($v) => is_numeric($v))->max() ?? $default['annual_months_free']),
                'premium_price' => '$'.self::money(PricingRules::price('premium')),
                'private_price' => '$'.self::money(PricingRules::price('private_network')),
                'ai_free' => (string) AiCredits::setting('per_seat'),
                'ai_premium' => (string) AiCredits::setting('per_seat_premium'),
                'industries' => (string) DB::table('industries')->where('status', 'active')->count(),
                'cities' => number_format(DB::table('cities')->where('is_enabled', true)->count()),
            ];
        });
    }

    /** @return array<string, string> */
    public static function industry(Industry $i): array
    {
        $t = $i->terminology;

        return ['industry' => $i->name, 'pro' => $t['pro'], 'pros' => $t['pros'], 'client' => $t['client'], 'clients' => $t['clients'], 'territory' => $i->territory_unit, 'industry_slug' => $i->slug];
    }

    /**
     * Fill placeholders in a string; unknown ones are left as they are (so an editor sees them).
     *
     * @param  array<string, string>  $extra
     */
    public static function fill(string $text, array $extra = []): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }
        $vars = [...self::site(), ...$extra];

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
    }

    /**
     * Fill every string in a structure (blocks, items, plans).
     *
     * @param  array<mixed>  $data
     * @param  array<string, string>  $extra
     * @return array<mixed>
     */
    public static function fillAll(array $data, array $extra = []): array
    {
        array_walk_recursive($data, function (&$v) use ($extra) {
            if (is_string($v)) {
                $v = self::fill($v, $extra);
            }
        });

        return $data;
    }

    /**
     * Fill only the given placeholders (an industry's words), leaving live figures like {seat_price} as placeholders.
     *
     * @param  array<mixed>  $data
     * @param  array<string, string>  $vars
     * @return array<mixed>
     */
    public static function fillOnly(array $data, array $vars): array
    {
        array_walk_recursive($data, function (&$v) use ($vars) {
            if (is_string($v)) {
                $v = (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $v);
            }
        });

        return $data;
    }

    public static function forget(): void
    {
        Cache::forget('cms.tokens');
    }

    private static function money(float $v): string
    {
        return fmod($v, 1.0) === 0.0 ? (string) (int) $v : number_format($v, 2);
    }
}
