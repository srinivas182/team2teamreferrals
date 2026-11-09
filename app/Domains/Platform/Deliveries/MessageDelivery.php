<?php

declare(strict_types=1);

namespace App\Domains\Platform\Deliveries;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $channel
 * @property string $provider
 * @property string $recipient
 * @property ?string $subject
 * @property string $status
 * @property ?string $provider_message_id
 * @property ?string $error
 * @property ?int $user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class MessageDelivery extends Model
{
    public const STATUSES = ['sent', 'delivered', 'failed', 'bounced'];

    protected $fillable = ['channel', 'provider', 'recipient', 'subject', 'status', 'provider_message_id', 'error', 'user_id'];

    protected $attributes = ['status' => 'sent', 'subject' => null, 'provider_message_id' => null, 'error' => null, 'user_id' => null];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
