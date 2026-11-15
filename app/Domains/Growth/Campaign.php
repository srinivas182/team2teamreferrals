<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drip campaign to prospects (Sprint 46b).
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $kind
 * @property string $status
 * @property ?array<string, string> $audience
 * @property string $goal
 * @property int $window_start
 * @property int $window_end
 * @property bool $weekdays_only
 * @property int $daily_cap
 * @property ?int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CampaignStep> $steps
 */
final class Campaign extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'kind', 'status', 'audience', 'goal', 'window_start', 'window_end', 'weekdays_only', 'daily_cap', 'created_by'];

    protected $attributes = ['kind' => 'drip', 'status' => 'draft', 'goal' => 'joined', 'window_start' => 9, 'window_end' => 17, 'weekdays_only' => true, 'daily_cap' => 200];

    protected function casts(): array
    {
        return ['audience' => 'array', 'weekdays_only' => 'boolean', 'window_start' => 'integer', 'window_end' => 'integer', 'daily_cap' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return HasMany<CampaignStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }
}
