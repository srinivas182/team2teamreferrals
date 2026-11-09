<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Models;

use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A network of pros in one industry: one seat per territory. Rules and pricing are set per group.
 *
 * @property int $id
 * @property int $industry_id
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property string $status
 * @property bool $is_launch
 * @property array{accept_hours: int, first_contact_hours: int, update_days: int, challenge_days: int, challenge_threshold: int, company_cap_pct: int, waitlist: string} $rules
 * @property array{seat_price: int, founding_price: int, trial_months: int, value_guarantee: bool, annual_months_free: int, discounts: array<int|string, int>} $pricing
 */
final class Group extends Model
{
    protected $fillable = ['industry_id', 'slug', 'name', 'description', 'status', 'is_launch', 'rules', 'pricing'];

    protected $attributes = ['status' => 'draft', 'is_launch' => false, 'description' => null];

    protected function casts(): array
    {
        return ['rules' => 'array', 'pricing' => 'array', 'is_launch' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return array{accept_hours: int, first_contact_hours: int, update_days: int, challenge_days: int, challenge_threshold: int, company_cap_pct: int, waitlist: string} */
    public static function defaultRules(): array
    {
        return ['accept_hours' => 24, 'first_contact_hours' => 24, 'update_days' => 7, 'challenge_days' => 60, 'challenge_threshold' => 70, 'company_cap_pct' => 25, 'waitlist' => 'trust'];
    }

    /** @return array{seat_price: int, founding_price: int, trial_months: int, value_guarantee: bool, annual_months_free: int, discounts: array<int|string, int>} */
    public static function defaultPricing(Industry $industry): array
    {
        // Sample prices for the pilot; the value guarantee is never allowed where fees must not depend on referrals (Tier C).
        return ['seat_price' => 79, 'founding_price' => 59, 'trial_months' => 3, 'value_guarantee' => $industry->tier->allowsValueGuarantee(), 'annual_months_free' => 2, 'discounts' => ['2' => 10, '5' => 15, '20' => 25]];
    }
}
