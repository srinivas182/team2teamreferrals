<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\DB;

/**
 * Cross-industry introductions: a member introduces a client to the seat holder of another industry in the same
 * city, through the normal referral flow. A fee applies only when both industries allow fees (RESPA prohibits
 * referral fees between settlement-service providers in either direction); every other pairing is a credited
 * introduction with no fee. Admins can block specific pairings, with a counsel note.
 */
final class Introductions
{
    /** Which industry families are often needed together, for suggestions on a referral. */
    public const OFTEN_TOGETHER = [
        'property' => ['money', 'home', 'legal', 'life'],
        'money' => ['property', 'legal', 'business'],
        'home' => ['property', 'money'],
        'legal' => ['money', 'property', 'care'],
        'care' => ['legal', 'money', 'life'],
        'life' => ['property', 'care'],
        'business' => ['money', 'legal'],
        'vehicles' => ['money'],
    ];

    public static function allowed(Industry $from, Industry $to): bool
    {
        if ($from->id === $to->id || $to->tier === Tier::Directory) {
            return false;
        }
        $rule = DB::table('industry_pairings')->where(['from_industry_id' => $from->id, 'to_industry_id' => $to->id])->value('allowed');

        return $rule === null ? true : (bool) $rule;
    }

    /** A fee only when both industries allow fees; otherwise a credited introduction. */
    public static function feeAllowed(Industry $from, Industry $to): bool
    {
        return $from->tier === Tier::Open && $to->tier === Tier::Open && self::allowed($from, $to);
    }

    /** Plain words for the form and the referral page. */
    public static function feeExplanation(Industry $from, Industry $to): string
    {
        return self::feeAllowed($from, $to)
            ? 'A referral fee can apply, under the '.$to->name.' network’s agreement.'
            : 'Fees between these industries aren’t allowed, so this is a credited introduction: no fee, and you earn credits when it’s accepted.';
    }

    /**
     * Networks often needed alongside this one, with who holds the seat in the city (the sender's own networks excluded).
     *
     * @param  list<int>  $exclude
     * @return list<array{slug: string, name: string, industry: string, team: ?string, fee: bool, explanation: string}>
     */
    public static function suggestions(Group $from, int $cityId, array $exclude = []): array
    {
        $families = self::OFTEN_TOGETHER[$from->industry->family] ?? [];
        $groups = Group::query()->with('industry')->whereHas('industry', fn ($q) => $q->whereIn('family', $families)->where('tier', '!=', Tier::Directory->value))
            ->whereNotIn('id', [$from->id, ...$exclude])->where('is_private', false)->orderBy('name')->get();
        $out = [];
        foreach ($groups as $g) {
            if (! self::allowed($from->industry, $g->industry)) {
                continue;
            }
            $team = DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where(['seats.group_id' => $g->id, 'seats.territory_id' => $cityId])->value('teams.name');
            $out[] = ['slug' => $g->slug, 'name' => $g->name, 'industry' => $g->industry->name, 'team' => $team !== null ? (string) $team : null,
                'fee' => self::feeAllowed($from->industry, $g->industry), 'explanation' => self::feeExplanation($from->industry, $g->industry)];
        }

        return $out;
    }

    /**
     * For a credited introduction, the agreement drops the fee clause and states that no money changes hands.
     *
     * @param  list<array{key: string, title: string, text: string, enabled?: bool, fee?: bool}>  $clauses
     * @return list<array{key: string, title: string, text: string, enabled?: bool, fee?: bool}>
     */
    public static function agreementClauses(Referral $ref, array $clauses): array
    {
        if ($ref->introduced_from_group_id === null || self::feeAllowed(self::fromIndustry($ref), $ref->group->industry)) {
            return $clauses;
        }
        $kept = array_values(array_filter($clauses, fn ($c) => $c['key'] !== 'fee' && ! ($c['fee'] ?? false)));
        if (! collect($kept)->contains('key', 'no_fee')) {
            $noFee = collect(\App\Domains\Network\Industries\Agreements\AgreementDefaults::for(Tier::Restricted))->firstWhere('key', 'no_fee');
            if (is_array($noFee)) {
                $kept[] = $noFee;
            }
        }

        return $kept;
    }

    public static function fromIndustry(Referral $ref): Industry
    {
        return Industry::query()->findOrFail((int) DB::table('groups')->where('id', $ref->introduced_from_group_id)->value('industry_id'));
    }
}
