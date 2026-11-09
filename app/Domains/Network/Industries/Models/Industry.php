<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Tier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $family
 * @property Tier $tier
 * @property string $territory_unit
 * @property array{pro: string, pros: string, client: string, clients: string, referral?: ?string, fee?: ?string} $terminology
 * @property string $icon
 * @property ?string $description
 * @property bool $license_required
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $compliance_approved_at
 * @property ?int $compliance_approved_by
 * @property ?string $compliance_note
 */
final class Industry extends Model
{
    public const FAMILIES = [
        'property' => 'Real estate and property', 'home' => 'Home services', 'money' => 'Insurance and financial', 'legal' => 'Legal',
        'care' => 'Health and care', 'vehicles' => 'Vehicles and equipment', 'business' => 'Business services', 'life' => 'Events, travel and lifestyle',
    ];

    public const TERRITORIES = ['city' => 'City', 'county' => 'County', 'metro' => 'Metro area', 'state' => 'State'];

    protected $fillable = ['slug', 'name', 'family', 'tier', 'territory_unit', 'terminology', 'icon', 'description', 'license_required'];

    protected $attributes = ['status' => 'draft', 'icon' => 'Briefcase', 'license_required' => true, 'description' => null, 'compliance_approved_at' => null, 'compliance_approved_by' => null, 'compliance_note' => null, 'catalogue_snapshot' => null];

    protected function casts(): array
    {
        return ['tier' => Tier::class, 'terminology' => 'array', 'license_required' => 'boolean', 'compliance_approved_at' => 'datetime', 'catalogue_snapshot' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Every term the industry uses, with tier-aware defaults: restricted industries call a referral an "introduction".
     *
     * @return array{pro: string, pros: string, client: string, clients: string, referral: string, fee: string}
     */
    public function terms(): array
    {
        $t = $this->terminology;

        return [
            'pro' => $t['pro'], 'pros' => $t['pros'], 'client' => $t['client'], 'clients' => $t['clients'],
            'referral' => ($t['referral'] ?? null) ?: ($this->tier === Tier::Open ? 'referral' : 'introduction'),
            'fee' => ($t['fee'] ?? null) ?: 'referral fee',
        ];
    }

    /**
     * Merge fields for messages and agreements. {client} stays free for the client's name.
     *
     * @return array<string, string>
     */
    public function mergeTerms(): array
    {
        $t = $this->terms();

        return ['pro' => $t['pro'], 'pros' => $t['pros'], 'client_term' => $t['client'], 'clients_term' => $t['clients'], 'referral_term' => $t['referral'], 'fee_term' => $t['fee']];
    }

    public function isApproved(): bool
    {
        return $this->compliance_approved_at !== null;
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    /** @return HasMany<IndustryLicensing, $this> */
    public function licensing(): HasMany
    {
        return $this->hasMany(IndustryLicensing::class);
    }

    /** @return HasMany<PlatformFeeRule, $this> */
    public function feeRules(): HasMany
    {
        return $this->hasMany(PlatformFeeRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compliance_approved_by');
    }
}
