<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $industry_id
 * @property ?int $group_id
 * @property string $mode
 * @property ?string $percent
 * @property ?int $flat_cents
 * @property ?array<string, int> $flat_by_temperature
 * @property string $charge_at
 * @property bool $enabled
 * @property ?\Illuminate\Support\Carbon $enabled_at
 */
final class PlatformFeeRule extends Model
{
    protected $fillable = ['industry_id', 'group_id', 'mode', 'percent', 'flat_cents', 'flat_by_temperature', 'charge_at'];

    protected $attributes = ['group_id' => null, 'mode' => 'none', 'percent' => null, 'flat_cents' => null, 'flat_by_temperature' => null, 'charge_at' => 'closing', 'enabled' => false, 'enabled_at' => null, 'enabled_by' => null];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'enabled_at' => 'datetime', 'flat_by_temperature' => 'array', 'flat_cents' => 'integer'];
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
