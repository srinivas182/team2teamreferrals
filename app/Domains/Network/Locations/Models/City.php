<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Models;

use App\Domains\Network\Locations\PlaceName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $state_id
 * @property ?int $county_id
 * @property string $name
 * @property string $slug
 * @property string $search_name
 * @property int $population
 * @property bool $is_enabled
 * @property-read State $state
 * @property-read ?County $county
 */
final class City extends Model
{
    protected $fillable = ['state_id', 'county_id', 'name', 'slug', 'search_name', 'lat', 'lon', 'population', 'is_enabled'];

    protected $attributes = ['county_id' => null, 'population' => 0, 'is_enabled' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'population' => 'integer'];
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<County, $this> */
    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    /** @return HasMany<CityAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(CityAlias::class);
    }

    /**
     * Type-ahead: prefix match on the name or any alias, largest places first.
     *
     * @param  Builder<City>  $q
     */
    public function scopeLookup(Builder $q, string $term): void
    {
        $t = PlaceName::normalize($term);
        $q->where(fn (Builder $w) => $w->where('search_name', 'like', $t.'%')
            ->orWhereIn('id', CityAlias::query()->select('city_id')->where('search_name', 'like', $t.'%')))
            ->orderByDesc('population')->orderBy('name');
    }
}
