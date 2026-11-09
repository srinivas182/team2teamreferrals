<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $channel
 * @property ?int $industry_id
 * @property int $version
 * @property ?string $subject
 * @property string $body
 * @property bool $is_active
 * @property ?int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
final class NotificationTemplate extends Model
{
    protected $fillable = ['type', 'channel', 'industry_id', 'version', 'subject', 'body', 'is_active', 'created_by'];

    protected $attributes = ['industry_id' => null, 'subject' => null, 'is_active' => false, 'created_by' => null];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
