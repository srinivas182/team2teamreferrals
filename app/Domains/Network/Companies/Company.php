<?php

declare(strict_types=1);

namespace App\Domains\Network\Companies;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $admin_id
 * @property-read User $admin
 */
final class Company extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'admin_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return HasMany<Team, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public static function administeredBy(User $user): ?self
    {
        return self::query()->where('admin_id', $user->id)->first();
    }
}
