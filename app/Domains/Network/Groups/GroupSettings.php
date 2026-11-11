<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups;

use App\Domains\Network\Groups\Models\Group;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validates a group's rules and pricing, including the compliance locks that come from the industry's tier. */
final class GroupSettings
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{seat_price: int, founding_price: int, trial_months: int, value_guarantee: bool, annual_months_free: int, discounts: array<int|string, int>}
     */
    public static function pricing(Group $group, array $input): array
    {
        $v = Validator::make($input, [
            'seat_price' => ['required', 'integer', 'min:0', 'max:5000'],
            'founding_price' => ['required', 'integer', 'min:0', 'lte:seat_price'],
            'trial_months' => ['required', 'integer', 'min:0', 'max:12'],
            'value_guarantee' => ['required', 'boolean'],
            'annual_months_free' => ['required', 'integer', 'min:0', 'max:6'],
            'discounts' => ['required', 'array'],
            'discounts.2' => ['required', 'integer', 'min:0', 'max:50'],
            'discounts.5' => ['required', 'integer', 'min:0', 'max:50'],
            'discounts.20' => ['required', 'integer', 'min:0', 'max:50'],
        ], ['founding_price.lte' => 'The founding price can’t be higher than the seat price.'])->validate();

        if ($v['value_guarantee'] && ! $group->industry->tier->allowsValueGuarantee()) {
            throw ValidationException::withMessages(['value_guarantee' => 'This industry’s tier needs fees that are the same for everyone and don’t depend on referrals, so the value guarantee must stay off.']);
        }
        if (! ($v['discounts'][2] <= $v['discounts'][5] && $v['discounts'][5] <= $v['discounts'][20])) {
            throw ValidationException::withMessages(['discounts' => 'Larger seat counts should get the same or a bigger discount.']);
        }

        return [
            'seat_price' => (int) $v['seat_price'], 'founding_price' => (int) $v['founding_price'], 'trial_months' => (int) $v['trial_months'],
            'value_guarantee' => (bool) $v['value_guarantee'], 'annual_months_free' => (int) $v['annual_months_free'],
            'discounts' => ['2' => (int) $v['discounts'][2], '5' => (int) $v['discounts'][5], '20' => (int) $v['discounts'][20]],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{accept_hours: int, first_contact_hours: int, update_days: int, challenge_days: int, challenge_threshold: int, company_cap_pct: int, waitlist: string, grace_days: int, release_days: int, offer_hours: int, auto_approve: bool, training_required: bool, training_grace_days: int, training_since: ?string}
     */
    public static function rules(array $input): array
    {
        $v = Validator::make($input, [
            'accept_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'first_contact_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'update_days' => ['required', 'integer', 'min:1', 'max:60'],
            'challenge_days' => ['required', 'integer', 'min:30', 'max:365'],
            'challenge_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'company_cap_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'waitlist' => ['required', 'in:trust,date'],
            'grace_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'release_days' => ['sometimes', 'integer', 'min:7', 'max:90', 'gt:grace_days'],
            'offer_hours' => ['sometimes', 'integer', 'min:24', 'max:168'],
            'auto_approve' => ['sometimes', 'boolean'],
            'training_required' => ['sometimes', 'boolean'],
            'credit_sent' => ['sometimes', 'integer', 'between:0,10'], 'credit_closed' => ['sometimes', 'integer', 'between:0,20'], 'credit_received' => ['sometimes', 'integer', 'between:-10,0'],
            'min_balance_enabled' => ['sometimes', 'boolean'], 'min_balance' => ['sometimes', 'integer', 'between:-100,0'],
            'training_grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ], ['release_days.gt' => 'A seat must be released after the grace period ends.'])->validate();

        return ['accept_hours' => (int) $v['accept_hours'], 'first_contact_hours' => (int) $v['first_contact_hours'], 'update_days' => (int) $v['update_days'],
            'challenge_days' => (int) $v['challenge_days'], 'challenge_threshold' => (int) $v['challenge_threshold'], 'company_cap_pct' => (int) $v['company_cap_pct'], 'waitlist' => (string) $v['waitlist'],
            'grace_days' => (int) ($v['grace_days'] ?? 7), 'release_days' => (int) ($v['release_days'] ?? 30), 'offer_hours' => (int) ($v['offer_hours'] ?? 72), 'auto_approve' => (bool) ($v['auto_approve'] ?? false),
            'training_required' => (bool) ($v['training_required'] ?? true), 'training_grace_days' => (int) ($v['training_grace_days'] ?? 14), 'training_since' => isset($input['training_since']) && is_string($input['training_since']) ? $input['training_since'] : null,
            'credit_sent' => (int) ($v['credit_sent'] ?? 1), 'credit_closed' => (int) ($v['credit_closed'] ?? 2), 'credit_received' => (int) ($v['credit_received'] ?? -1),
            'min_balance_enabled' => (bool) ($v['min_balance_enabled'] ?? false), 'min_balance' => (int) ($v['min_balance'] ?? -5)];
    }

    /**
     * Who a change affects. Seats arrive in Sprint 11; until then every count is zero.
     *
     * @return array{seats: int, trial: int, paying: int}
     */
    public static function affected(Group $group): array
    {
        $q = \App\Domains\Network\Seats\Models\Seat::query()->where('group_id', $group->id)->whereNotNull('primary_team_id');

        return ['seats' => (clone $q)->count(), 'trial' => (clone $q)->where('status', 'trial')->count(), 'paying' => (clone $q)->whereIn('status', ['active', 'grace'])->count()];
    }

    /**
     * Group health for the admin card and the owner overview. Referral figures fill in from Sprint 15.
     *
     * @return array{owners: int, seats: int, referralsThisMonth: ?int, avgAcceptMinutes: ?int, quietSeats: ?int}
     */
    public static function health(Group $group): array
    {
        return ['owners' => $group->owners()->count(), 'seats' => \App\Domains\Network\Seats\Models\Seat::query()->where('group_id', $group->id)->whereNotNull('primary_team_id')->count(), 'referralsThisMonth' => null, 'avgAcceptMinutes' => null, 'quietSeats' => null];
    }
}
