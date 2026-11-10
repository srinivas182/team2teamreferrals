<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $channel
 * @property string $title
 * @property string $body
 * @property ?string $url
 * @property ?string $reply_to
 * @property \Illuminate\Support\Carbon $deliver_after
 * @property ?\Illuminate\Support\Carbon $sent_at
 */
final class OutboxItem extends Model
{
    protected $table = 'notification_outbox';

    protected $fillable = ['user_id', 'type', 'channel', 'title', 'body', 'url', 'reply_to', 'deliver_after', 'sent_at'];

    protected $attributes = ['url' => null, 'sent_at' => null];

    protected function casts(): array
    {
        return ['deliver_after' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
