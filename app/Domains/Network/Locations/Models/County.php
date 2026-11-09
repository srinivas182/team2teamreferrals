<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $state_id
 * @property string $fips
 * @property string $name
 */
final class County extends Model
{
    protected $fillable = ['state_id', 'fips', 'name', 'lat', 'lon'];

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsToMany<Metro, $this> */
    public function metros(): BelongsToMany
    {
        return $this->belongsToMany(Metro::class);
    }
}
