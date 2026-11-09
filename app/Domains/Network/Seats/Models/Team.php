<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $leader_id
 * @property-read User $leader
 */
final class Team extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'leader_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<User, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /** Every member leads a team (a team of one to start); onboarding in Sprint 12 adds team names and members. */
    public static function forLeader(User $user): self
    {
        return self::query()->firstOrCreate(['leader_id' => $user->id], ['name' => $user->name]);
    }
}
