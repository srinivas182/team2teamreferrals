<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's Google or Microsoft calendar connection. Tokens are encrypted and never leave the server.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $provider
 * @property string $status
 * @property array<string, mixed> $credentials
 * @property ?string $account_email
 * @property string $calendar_id
 * @property ?string $calendar_name
 * @property list<string> $kinds
 * @property ?string $last_error
 * @property ?\Illuminate\Support\Carbon $last_synced_at
 * @property-read User $user
 */
final class CalendarConnection extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'provider', 'status', 'credentials', 'account_email', 'calendar_id', 'calendar_name', 'kinds'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'kinds' => 'array', 'last_synced_at' => 'datetime'];
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
}
