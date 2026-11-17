<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support ticket (Sprint 48a).
 *
 * @property int $id
 * @property string $ulid
 * @property string $number
 * @property ?int $user_id
 * @property ?int $partner_id
 * @property string $name
 * @property string $email
 * @property string $subject
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property string $source
 * @property ?int $assignee_id
 * @property ?string $access_hash
 * @property ?\Illuminate\Support\Carbon $first_response_due_at
 * @property ?\Illuminate\Support\Carbon $resolution_due_at
 * @property ?\Illuminate\Support\Carbon $first_responded_at
 * @property ?\Illuminate\Support\Carbon $breach_alerted_at
 * @property ?\Illuminate\Support\Carbon $solved_at
 * @property ?\Illuminate\Support\Carbon $last_customer_at
 * @property ?\Illuminate\Support\Carbon $last_staff_at
 * @property ?string $rating
 * @property ?string $rating_comment
 * @property ?string $ip_hash
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ?User $user
 * @property-read ?User $assignee
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TicketMessage> $messages
 */
final class Ticket extends Model
{
    use HasUlids;

    protected $table = 'support_tickets';

    public const CATEGORIES = ['account' => 'Account and sign-in', 'billing' => 'Billing', 'referrals' => 'Referrals', 'seats' => 'Seats and networks', 'partners' => 'Partner directory',
        'technical' => 'Something isn’t working', 'other' => 'Something else'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const STATUSES = ['open' => 'Open', 'pending' => 'Waiting on you', 'solved' => 'Solved', 'closed' => 'Closed'];

    protected $fillable = ['user_id', 'partner_id', 'name', 'email', 'subject', 'category', 'priority', 'status', 'source', 'assignee_id', 'ip_hash'];

    protected $attributes = ['status' => 'open', 'priority' => 'normal', 'category' => 'other', 'source' => 'web'];

    protected function casts(): array
    {
        return ['first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime', 'breach_alerted_at' => 'datetime', 'solved_at' => 'datetime',
            'last_customer_at' => 'datetime', 'last_staff_at' => 'datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?: 'there';
    }
}
