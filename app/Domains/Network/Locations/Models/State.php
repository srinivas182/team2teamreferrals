<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $country
 * @property string $code
 * @property string $name
 * @property ?string $fips
 * @property bool $is_enabled
 */
final class State extends Model
{
    protected $fillable = ['country', 'code', 'name', 'fips', 'is_enabled'];

    protected $attributes = ['country' => 'US', 'is_enabled' => true, 'fips' => null];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** @return HasMany<City, $this> */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /** @return HasMany<County, $this> */
    public function counties(): HasMany
    {
        return $this->hasMany(County::class);
    }
}
