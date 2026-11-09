<?php

declare(strict_types=1);

namespace App\Domains\Platform\Deliveries;

use Illuminate\Database\Eloquent\Model;

/**
 * A number that replied STOP. Required by US texting law (TCPA): nothing is sent until START.
 *
 * @property int $id
 * @property string $phone
 * @property \Illuminate\Support\Carbon $opted_out_at
 */
final class SmsOptOut extends Model
{
    public $timestamps = false;

    protected $fillable = ['phone', 'opted_out_at'];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public static function blocks(string $phone): bool
    {
        return self::query()->where('phone', $phone)->exists();
    }
}
