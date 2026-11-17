<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A local business advertising in the partner directory (Sprint 47).
 *
 * @property int $id
 * @property string $ulid
 * @property string $slug
 * @property int $category_id
 * @property ?int $user_id
 * @property string $business_name
 * @property string $contact_name
 * @property string $email
 * @property ?string $phone
 * @property ?string $website
 * @property string $description
 * @property ?string $logo_path
 * @property ?string $license_number
 * @property ?string $license_state
 * @property ?\Illuminate\Support\Carbon $license_verified_at
 * @property ?string $offer_title
 * @property ?string $offer_details
 * @property ?\Illuminate\Support\Carbon $offer_expires_on
 * @property string $status
 * @property ?string $status_reason
 * @property ?int $reviewed_by
 * @property ?\Illuminate\Support\Carbon $reviewed_at
 * @property ?string $ip_hash
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read PartnerCategory $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PartnerListing> $listings
 */
final class Partner extends Model
{
    use HasUlids;

    public const STATUSES = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved', 'suspended' => 'Suspended'];

    protected $fillable = ['slug', 'category_id', 'user_id', 'business_name', 'contact_name', 'email', 'phone', 'website', 'description', 'logo_path', 'license_number', 'license_state',
        'offer_title', 'offer_details', 'offer_expires_on', 'ip_hash'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['license_verified_at' => 'datetime', 'reviewed_at' => 'datetime', 'offer_expires_on' => 'date'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<PartnerCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class);
    }

    /** @return HasMany<PartnerListing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(PartnerListing::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path !== null ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * The offer, while it is still running.
     *
     * @return array{title: string, details: ?string, expires: ?string}|null
     */
    public function offer(): ?array
    {
        if ($this->offer_title === null || ($this->offer_expires_on !== null && $this->offer_expires_on->endOfDay()->isPast())) {
            return null;
        }

        return ['title' => $this->offer_title, 'details' => $this->offer_details, 'expires' => $this->offer_expires_on?->toDateString()];
    }

    public function path(): string
    {
        return '/partner/'.$this->slug;
    }
}
