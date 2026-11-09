<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Another name a city goes by ("NYC", "Philly"). Spelling variants like "St."/"Saint" need no alias.
 *
 * @property int $id
 * @property int $city_id
 * @property string $alias
 * @property string $search_name
 */
final class CityAlias extends Model
{
    protected $fillable = ['city_id', 'alias', 'search_name'];
}
