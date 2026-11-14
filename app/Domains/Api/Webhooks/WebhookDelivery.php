<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event sent (or being sent) to one endpoint, with the outcome of its latest attempt. Kept 30 days.
 *
 * @property int $id
 * @property string $ulid
 * @property int $webhook_endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property string $payload
 * @property string $status
 * @property int $attempts
 * @property ?\Illuminate\Support\Carbon $next_attempt_at
 * @property ?int $response_status
 * @property ?string $response_body
 * @property ?string $error
 * @property ?int $duration_ms
 * @property ?\Illuminate\Support\Carbon $delivered_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
final class WebhookDelivery extends Model
{
    use HasUlids;

    protected $fillable = ['webhook_endpoint_id', 'event_id', 'event_type', 'payload', 'status', 'attempts', 'next_attempt_at'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'response_status' => 'integer', 'duration_ms' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
