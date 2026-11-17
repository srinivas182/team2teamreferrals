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
 * @property-read Partner $partner
 * @property-read City $city
 */
final class PartnerListing extends Model
{
    protected $fillable = ['partner_id', 'category_id', 'city_id', 'status'];

    protected $attributes = ['status' => 'active'];

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
