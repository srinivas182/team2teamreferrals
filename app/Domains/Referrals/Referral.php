<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $ulid
 * @property int $group_id
 * @property int $seat_id
 * @property int $sender_team_id
 * @property int $sender_user_id
 * @property ?int $receiver_team_id
 * @property string $routed_to
 * @property string $status
 * @property string $client_name
 * @property ?string $client_phone
 * @property ?string $client_email
 * @property array<string, mixed> $answers
 * @property ?string $notes
 * @property \Illuminate\Support\Carbon $consent_at
 * @property string $consent_method
 * @property string $consent_text
 * @property \Illuminate\Support\Carbon $respond_by
 * @property ?\Illuminate\Support\Carbon $accepted_at
 * @property ?string $decline_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Group $group
 * @property-read Seat $seat
 * @property-read Team $sender
 * @property-read ?Team $receiver
 */
final class Referral extends Model
{
    use HasUlids;

    protected $fillable = ['group_id', 'seat_id', 'sender_team_id', 'sender_user_id', 'receiver_team_id', 'routed_to', 'status', 'client_name', 'client_phone', 'client_email', 'answers', 'notes', 'consent_at', 'consent_method', 'consent_text', 'respond_by', 'accepted_at', 'decline_reason'];

    protected $attributes = ['status' => 'sent', 'notes' => null, 'client_phone' => null, 'client_email' => null, 'accepted_at' => null, 'decline_reason' => null];

    protected function casts(): array
    {
        return ['answers' => 'array', 'consent_at' => 'datetime', 'respond_by' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** "Tom B.": what the receiver sees until they accept (and in notifications), so contact details stay private. */
    public function shortClientName(): string
    {
        $parts = preg_split('/\s+/', trim($this->client_name)) ?: [];
        $first = (string) ($parts[0] ?? 'Client');

        return count($parts) > 1 ? $first.' '.mb_substr((string) end($parts), 0, 1).'.' : $first;
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Seat, $this> */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'sender_team_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'receiver_team_id');
    }
}
