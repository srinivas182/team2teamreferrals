<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Daily counts per partner and city: views (seen in a list or on its page), clicks (phone, website), enquiries and
 * member shares. Views and clicks come from the browser, so bots and repeats are left out: known crawlers and empty
 * user agents are ignored, and one visitor counts once per partner per day.
 */
final class PartnerStats
{
    public const FIELDS = ['views', 'clicks', 'enquiries', 'shares'];

    private const BOTS = '/bot|crawl|spider|slurp|scrap|preview|headless|lighthouse|pagespeed|facebookexternalhit|embedly|curl|wget|python|httpclient|okhttp|java\/|go-http|axios|node-fetch|monitor|uptime/i';

    public static function bump(int $partnerId, ?int $cityId, string $field, int $by = 1): void
    {
        $key = ['partner_id' => $partnerId, 'city_id' => $cityId ?? 0, 'day' => now()->toDateString()];
        DB::table('partner_stats')->insertOrIgnore($key);
        DB::table('partner_stats')->where($key)->increment($field, $by);
    }

    public static function isBot(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return $ua === '' || preg_match(self::BOTS, $ua) === 1;
    }

    /** Count a view or click once per visitor, partner and day. Returns whether it was counted. */
    public static function record(Request $request, Partner $partner, ?int $cityId, string $field): bool
    {
        if (self::isBot($request)) {
            return false;
        }
        $visitor = hash('sha256', $request->ip().'|'.$request->userAgent().'|'.config('app.key'));
        if (! Cache::add("pstat:{$field}:{$partner->id}:{$visitor}:".now()->toDateString(), 1, now()->endOfDay())) {
            return false;
        }
        self::bump($partner->id, $cityId, $field);

        return true;
    }

    /**
     * Totals, by city and by day for a date range, with what was paid in it and the cost per enquiry.
     *
     * @return array{totals: array<string, int>, cities: list<array<string, mixed>>, days: list<array<string, mixed>>, spentCents: int, costPerEnquiry: ?float}
     */
    public static function report(Partner $partner, string $from, string $to): array
    {
        $base = DB::table('partner_stats')->where('partner_id', $partner->id)->whereBetween('day', [$from, $to]);
        $sum = implode(', ', array_map(fn ($f) => "sum({$f}) as {$f}", self::FIELDS));
        $t = (clone $base)->selectRaw($sum)->first();
        $totals = [];
        foreach (self::FIELDS as $f) {
            $totals[$f] = (int) ($t->{$f} ?? 0);
        }
        $names = DB::table('cities as c')->join('states as s', 's.id', '=', 'c.state_id')->whereIn('c.id', (clone $base)->distinct()->pluck('city_id'))
            ->get(['c.id', 'c.name', 's.code'])->mapWithKeys(fn ($c) => [(int) $c->id => $c->name.', '.$c->code]);
        $cities = array_values((clone $base)->groupBy('city_id')->selectRaw('city_id, '.$sum)->get()
            ->map(fn ($r) => ['city' => $names[(int) $r->city_id] ?? 'Your page', ...array_map('intval', array_intersect_key((array) $r, array_flip(self::FIELDS)))])->sortByDesc('views')->all());
        $days = array_values((clone $base)->groupBy('day')->orderBy('day')->selectRaw('day, '.$sum)->get()
            ->map(fn ($r) => ['day' => substr((string) $r->day, 0, 10), ...array_map('intval', array_intersect_key((array) $r, array_flip(self::FIELDS)))])->all());
        $spent = 0;
        foreach (DB::table('invoices')->where(['owner_type' => 'partner', 'owner_id' => $partner->id, 'status' => 'paid'])->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])->pluck('lines') as $lines) {
            foreach ((array) json_decode((string) $lines, true) as $l) {
                $spent += ($l['tax'] ?? false) ? 0 : (int) ($l['amount_cents'] ?? 0);
            }
        }

        return ['totals' => $totals, 'cities' => $cities, 'days' => $days, 'spentCents' => $spent, 'costPerEnquiry' => $totals['enquiries'] > 0 ? round($spent / 100 / $totals['enquiries'], 2) : null];
    }
}
