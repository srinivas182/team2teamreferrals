<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $cbsa_code
 * @property string $name
 * @property string $kind
 */
final class Metro extends Model
{
    protected $fillable = ['cbsa_code', 'name', 'kind'];

    /** @return BelongsToMany<County, $this> */
    public function counties(): BelongsToMany
    {
        return $this->belongsToMany(County::class);
    }
}
