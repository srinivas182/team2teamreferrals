<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\Network\Locations\Models\City;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One partner in one city (its service area).
 *
 * @property int $id
 * @property int $partner_id
 * @property int $category_id
 * @property int $city_id
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $waitlisted_at
 * @property-read Partner $partner
 * @property-read City $city
 */
final class PartnerListing extends Model
{
    protected $fillable = ['partner_id', 'category_id', 'city_id', 'status', 'waitlisted_at'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['waitlisted_at' => 'datetime'];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
