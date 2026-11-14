<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $ulid
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property string $mode
 * @property string $token_hash
 * @property string $prefix
 * @property list<string> $scopes
 * @property ?\Illuminate\Support\Carbon $last_used_at
 * @property ?string $last_used_ip
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 * @property-read Team $team
 * @property-read User $user
 */
final class ApiToken extends Model
{
    use HasUlids;

    protected $fillable = ['team_id', 'user_id', 'name', 'mode', 'token_hash', 'prefix', 'scopes', 'expires_at'];

    protected $hidden = ['token_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isTest(): bool
    {
        return $this->mode === 'test';
    }
}
