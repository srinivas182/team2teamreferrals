<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL a team asked us to send events to (Sprint 44b). Signing secrets are stored encrypted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $team_id
 * @property ?int $created_by
 * @property string $url
 * @property ?string $description
 * @property string $mode
 * @property list<string> $events
 * @property string $secret
 * @property ?string $previous_secret
 * @property ?\Illuminate\Support\Carbon $previous_secret_expires_at
 * @property bool $is_active
 * @property ?\Illuminate\Support\Carbon $failing_since
 * @property ?\Illuminate\Support\Carbon $disabled_at
 * @property ?string $disabled_reason
 * @property ?\Illuminate\Support\Carbon $last_success_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Team $team
 * @property-read ?User $creator
 */
final class WebhookEndpoint extends Model
{
    use HasUlids;

    protected $fillable = ['team_id', 'created_by', 'url', 'description', 'mode', 'events', 'secret', 'is_active'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'previous_secret' => 'encrypted', 'previous_secret_expires_at' => 'datetime', 'is_active' => 'boolean',
            'failing_since' => 'datetime', 'disabled_at' => 'datetime', 'last_success_at' => 'datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function wants(string $type): bool
    {
        return in_array('*', $this->events, true) || in_array($type, $this->events, true);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
