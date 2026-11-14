<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's connection to one CRM. Credentials are stored encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $ulid
 * @property int $team_id
 * @property string $provider
 * @property string $status
 * @property array<string, string> $credentials
 * @property array{sync_sent?: bool, sync_notes?: bool, assign_user_id?: ?string} $settings
 * @property ?string $account_name
 * @property ?list<array{id: string, name: string}> $remote_users
 * @property ?list<string> $remote_stages
 * @property ?string $last_error
 * @property ?\Illuminate\Support\Carbon $last_synced_at
 * @property ?int $connected_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Team $team
 * @property-read ?User $connector
 */
final class Integration extends Model
{
    use HasUlids;

    protected $fillable = ['team_id', 'provider', 'status', 'credentials', 'settings', 'account_name', 'remote_users', 'remote_stages', 'connected_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'settings' => 'array', 'remote_users' => 'array', 'remote_stages' => 'array', 'last_synced_at' => 'datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
