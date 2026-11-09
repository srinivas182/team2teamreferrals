<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats\Models;

use App\Domains\Network\Groups\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $group_id
 * @property string $territory_type
 * @property int $territory_id
 * @property string $territory_name
 * @property ?string $state_code
 * @property string $status
 * @property ?int $primary_team_id
 * @property ?int $backup_team_id
 * @property bool $founding
 * @property string $payer
 * @property ?\Illuminate\Support\Carbon $trial_ends_at
 * @property ?\Illuminate\Support\Carbon $grace_started_at
 * @property ?\Illuminate\Support\Carbon $inactive_at
 * @property-read Group $group
 * @property-read ?Team $primary
 * @property-read ?Team $backup
 */
final class Seat extends Model
{
    public const RECEIVES_REFERRALS = ['trial', 'active', 'grace'];

    protected $fillable = ['group_id', 'territory_type', 'territory_id', 'territory_name', 'state_code', 'status', 'primary_team_id', 'backup_team_id', 'founding', 'payer', 'trial_ends_at', 'grace_started_at', 'inactive_at'];

    protected $attributes = ['status' => 'open', 'founding' => false, 'payer' => 'team', 'primary_team_id' => null, 'backup_team_id' => null, 'trial_ends_at' => null, 'grace_started_at' => null, 'inactive_at' => null, 'state_code' => null];

    protected function casts(): array
    {
        return ['founding' => 'boolean', 'trial_ends_at' => 'datetime', 'grace_started_at' => 'datetime', 'inactive_at' => 'datetime'];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'primary_team_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'backup_team_id');
    }

    /** @return HasMany<SeatWaitlist, $this> */
    public function waitlist(): HasMany
    {
        return $this->hasMany(SeatWaitlist::class)->orderByDesc('score')->orderBy('joined_at');
    }

    /** Who gets new referrals for this territory right now: the holder, or the backup while the holder is inactive. */
    public function receiver(): ?Team
    {
        if (in_array($this->status, self::RECEIVES_REFERRALS, true)) {
            return $this->primary;
        }

        return $this->status === 'inactive' ? $this->backup : null;
    }
}
