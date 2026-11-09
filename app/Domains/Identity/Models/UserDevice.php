<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $label
 * @property ?string $ip_address
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property ?\Illuminate\Support\Carbon $two_factor_trusted_until
 */
final class UserDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'ip_address', 'last_seen_at', 'two_factor_trusted_until'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'two_factor_trusted_until' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTrustedForTwoFactor(): bool
    {
        return $this->two_factor_trusted_until !== null && $this->two_factor_trusted_until->isFuture();
    }
}
