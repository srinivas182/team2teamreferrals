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
 * @property-read Industry $industry every group belongs to an industry (required foreign key)
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property string $status
 * @property string $visibility
 * @property ?string $photo_path
 * @property bool $is_launch
 * @property bool $is_private
 * @property ?string $region
 * @property ?int $parent_group_id
 * @property array{accept_hours: int, first_contact_hours: int, update_days: int, challenge_days: int, challenge_threshold: int, company_cap_pct: int, waitlist: string} $rules
 * @property array{seat_price: int, founding_price: int, trial_months: int, value_guarantee: bool, annual_months_free: int, discounts: array<int|string, int>} $pricing
 */
final class Group extends Model
{
    protected $fillable = ['industry_id', 'slug', 'name', 'description', 'photo_path', 'visibility', 'status', 'is_launch', 'is_private', 'region', 'parent_group_id', 'company_id', 'rules', 'pricing'];

    protected $attributes = ['status' => 'draft', 'visibility' => 'open', 'is_launch' => false, 'is_private' => false, 'region' => null, 'parent_group_id' => null, 'company_id' => null, 'description' => null, 'photo_path' => null];

    protected function casts(): array
    {
        return ['rules' => 'array', 'pricing' => 'array', 'is_launch' => 'boolean', 'is_private' => 'boolean'];
    }

    /**
     * Groups members can find and apply to. Private company networks are never listed.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Group>  $query
     */
    public function scopeListed(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('is_private', false)->where('status', 'live');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Domains\Identity\Models\User, $this> */
    public function owners(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domains\Identity\Models\User::class, 'group_owners')->withTimestamps();
    }

    public function isOwnedBy(?\App\Domains\Identity\Models\User $user): bool
    {
        return $user !== null && $this->owners()->whereKey($user->getKey())->exists();
    }

    /** A rule with its default, so groups created before a rule existed still behave correctly. */
    public function rule(string $key): int|string|bool|null
    {
        /** @var int|string|bool|null */
        return [...self::defaultRules(), ...$this->rules][$key];
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return array{accept_hours: int, first_contact_hours: int, update_days: int, challenge_days: int, challenge_threshold: int, company_cap_pct: int, waitlist: string, grace_days: int, release_days: int, offer_hours: int, auto_approve: bool, training_required: bool, training_grace_days: int, training_since: ?string} */
    public static function defaultRules(): array
    {
        return ['accept_hours' => 24, 'first_contact_hours' => 24, 'update_days' => 7, 'challenge_days' => 60, 'challenge_threshold' => 70, 'company_cap_pct' => 25, 'waitlist' => 'trust', 'grace_days' => 7, 'release_days' => 30, 'offer_hours' => 72, 'auto_approve' => false, 'training_required' => true, 'training_grace_days' => 14, 'training_since' => null,
            'credit_sent' => 1, 'credit_closed' => 2, 'credit_received' => -1, 'min_balance_enabled' => false, 'min_balance' => -5];
    }

    /** @return array{seat_price: int, founding_price: int, trial_months: int, value_guarantee: bool, annual_months_free: int, discounts: array<int|string, int>} */
    public static function defaultPricing(Industry $industry): array
    {
        // $10 per city per group; founders pay the same $10, locked while they hold the seat. Multi-city discounts off by default. The value guarantee is never allowed where fees must not depend on referrals (Tier C).
        return ['seat_price' => 10, 'founding_price' => 10, 'trial_months' => 3, 'value_guarantee' => $industry->tier->allowsValueGuarantee(), 'annual_months_free' => 2, 'discounts' => ['2' => 0, '5' => 0, '20' => 0]];
    }
}
