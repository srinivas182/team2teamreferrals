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
 * @property ?string $client_token
 * @property int $group_id
 * @property int $seat_id
 * @property int $sender_team_id
 * @property int $sender_user_id
 * @property ?int $receiver_team_id
 * @property string $routed_to
 * @property string $status
 * @property ?string $stage
 * @property ?\Illuminate\Support\Carbon $stage_changed_at
 * @property ?\Illuminate\Support\Carbon $first_contact_at
 * @property ?\Illuminate\Support\Carbon $last_update_at
 * @property ?\Illuminate\Support\Carbon $closing_date
 * @property ?int $closing_value_cents
 * @property ?string $lost_reason
 * @property ?\Illuminate\Support\Carbon $closed_at
 * @property ?\Illuminate\Support\Carbon $last_nudged_at
 * @property string $client_name
 * @property ?string $client_phone
 * @property ?string $client_email
 * @property array<string, mixed> $answers
 * @property ?string $notes
 * @property \Illuminate\Support\Carbon $consent_at
 * @property string $consent_method
 * @property string $consent_text
 * @property ?\Illuminate\Support\Carbon $consent_requested_at
 * @property ?\Illuminate\Support\Carbon $client_confirmed_at
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

    protected $fillable = ['client_token', 'group_id', 'seat_id', 'sender_team_id', 'sender_user_id', 'receiver_team_id', 'routed_to', 'status', 'client_name', 'client_phone', 'client_email', 'answers', 'notes', 'consent_at', 'consent_method', 'consent_text', 'respond_by', 'accepted_at', 'decline_reason'];

    protected $attributes = ['client_token' => null, 'status' => 'sent', 'notes' => null, 'client_phone' => null, 'client_email' => null, 'accepted_at' => null, 'decline_reason' => null];

    protected function casts(): array
    {
        return ['answers' => 'array', 'consent_at' => 'datetime', 'consent_requested_at' => 'datetime', 'client_confirmed_at' => 'datetime', 'respond_by' => 'datetime', 'accepted_at' => 'datetime', 'stage_changed_at' => 'datetime', 'first_contact_at' => 'datetime', 'last_update_at' => 'datetime', 'closing_date' => 'date', 'closed_at' => 'datetime', 'last_nudged_at' => 'datetime'];
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

    /** Mask phone numbers and emails, e.g. in messages the receiver reads before accepting. */
    public static function maskContacts(string $text): string
    {
        $text = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email shown after accepting]', $text);

        return (string) preg_replace('/(?:\+?\d[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/', '[phone shown after accepting]', $text);
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
