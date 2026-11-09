<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $ulid
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property ?string $phone
 * @property ?\Illuminate\Support\Carbon $email_verified_at
 * @property ?\Illuminate\Support\Carbon $phone_verified_at
 * @property ?\Illuminate\Support\Carbon $last_login_at
 * @property ?string $two_factor_secret
 * @property ?\Illuminate\Support\Carbon $two_factor_confirmed_at
 * @property bool $is_active
 * @property string $timezone
 * @property-read string $name
 */
final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, LogsActivity, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /** Mirrors the column defaults so new, unsaved-then-saved users behave the same as loaded ones. */
    protected $attributes = [
        'is_active' => true,
        'timezone' => 'America/Denver',
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'last_login_at' => null,
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'password', 'timezone'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /**
     * ULIDs are the public identifier; the integer id stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** @return HasMany<UserDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /** Audit trail of account changes (before and after), never including secrets. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('accounts')
            ->logOnly(['first_name', 'last_name', 'email', 'phone', 'phone_verified_at', 'email_verified_at', 'is_active', 'timezone'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Account created',
                'updated' => 'Account details changed',
                'deleted' => 'Account deleted',
                'restored' => 'Account restored',
                default => ucfirst($event),
            });
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole([\App\Domains\Identity\Authorization\Permissions::SUPER_ADMIN, \App\Domains\Identity\Authorization\Permissions::STAFF]);
    }

    public function getPasskeyDisplayName(): string
    {
        return $this->name;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
