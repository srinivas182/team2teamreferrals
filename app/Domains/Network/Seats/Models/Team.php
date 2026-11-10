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
 * @property ?int $company_id
 * @property ?string $logo_path
 * @property ?string $bio
 * @property ?string $website
 * @property ?string $phone
 * @property ?array<string, string> $social
 * @property ?string $service_area
 * @property ?int $founded_year
 * @property ?string $score_warned_at
 * @property-read User $leader
 */
final class Team extends Model
{
    use HasUlids;

    public const ROLES = ['leader' => 'Leader', 'member' => 'Member', 'assistant' => 'Assistant'];

    protected $fillable = ['name', 'leader_id', 'company_id', 'logo_path', 'bio', 'website', 'phone', 'social', 'service_area', 'founded_year'];

    protected $attributes = ['company_id' => null, 'logo_path' => null, 'bio' => null, 'website' => null, 'phone' => null, 'social' => null, 'service_area' => null, 'founded_year' => null];

    protected function casts(): array
    {
        return ['social' => 'array'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<User, $this> */
    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')->withPivot('role')->withTimestamps();
    }

    public function roleOf(User $user): ?string
    {
        $role = \Illuminate\Support\Facades\DB::table('team_members')->where(['team_id' => $this->id, 'user_id' => $user->id])->value('role');

        return is_string($role) ? $role : null;
    }

    /** The team a person works in: the one they lead, or the one they belong to. */
    public static function of(User $user): ?self
    {
        $id = \Illuminate\Support\Facades\DB::table('team_members')->where('user_id', $user->id)->orderByRaw("case role when 'leader' then 0 else 1 end")->value('team_id');

        return $id === null ? null : self::query()->find((int) $id);
    }

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

    /** Every new pro starts as the leader of a team of one; they can rename it and invite others. */
    public static function forLeader(User $user): self
    {
        $team = self::query()->firstOrCreate(['leader_id' => $user->id], ['name' => $user->name]);
        \Illuminate\Support\Facades\DB::table('team_members')->insertOrIgnore(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'leader', 'created_at' => now(), 'updated_at' => now()]);

        return $team;
    }
}
