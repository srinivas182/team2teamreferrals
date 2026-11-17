<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a ticket: from the customer, from staff (or a staff-only note), or a system line.
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $author
 * @property ?int $user_id
 * @property string $body
 * @property bool $internal
 * @property string $via
 * @property ?list<array{path: string, name: string, size: int, type: string}> $attachments
 * @property ?string $email_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ?User $user
 */
final class TicketMessage extends Model
{
    protected $table = 'support_messages';

    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'author', 'user_id', 'body', 'internal', 'via', 'attachments', 'email_id'];

    protected function casts(): array
    {
        return ['internal' => 'boolean', 'attachments' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
