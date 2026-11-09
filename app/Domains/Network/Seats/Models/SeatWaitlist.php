<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $seat_id
 * @property int $team_id
 * @property int $score
 * @property \Illuminate\Support\Carbon $joined_at
 * @property-read Team $team
 */
final class SeatWaitlist extends Model
{
    public $timestamps = false;

    protected $table = 'seat_waitlist';

    protected $fillable = ['seat_id', 'team_id', 'score', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
