<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who might join a network (Sprint 46a).
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $business
 * @property ?string $phone
 * @property ?int $industry_id
 * @property ?int $group_id
 * @property ?int $city_id
 * @property ?string $state_code
 * @property string $stage
 * @property ?string $lost_reason
 * @property ?int $owner_id
 * @property string $source
 * @property ?string $source_detail
 * @property string $consent_basis
 * @property ?int $user_id
 * @property int $score
 * @property ?list<string> $tags
 * @property ?\Illuminate\Support\Carbon $last_activity_at
 * @property ?\Illuminate\Support\Carbon $alerted_at
 * @property ?\Illuminate\Support\Carbon $joined_at
 * @property ?\Illuminate\Support\Carbon $unsubscribed_at
 * @property ?int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ?Industry $industry
 * @property-read ?Group $group
 * @property-read ?City $city
 * @property-read ?User $owner
 */
final class Prospect extends Model
{
    use HasUlids;

    public const STAGES = ['new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'signed_up' => 'Signed up', 'applied' => 'Applied', 'joined' => 'Joined', 'lost' => 'Lost'];

    public const SOURCES = ['signup' => 'Signed up', 'reservation' => 'Pre-launch reservation', 'contact_form' => 'Contact form', 'import' => 'Imported', 'manual' => 'Added by hand'];

    protected $fillable = ['email', 'first_name', 'last_name', 'business', 'phone', 'industry_id', 'group_id', 'city_id', 'state_code', 'stage', 'owner_id', 'source', 'source_detail', 'consent_basis', 'user_id', 'tags', 'created_by'];

    protected $attributes = ['stage' => 'new', 'score' => 0];

    protected function casts(): array
    {
        return ['tags' => 'array', 'score' => 'integer', 'last_activity_at' => 'datetime', 'alerted_at' => 'datetime', 'joined_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function name(): string
    {
        $n = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $n !== '' ? $n : $this->email;
    }

    /** hot, warm or cold */
    public function temperature(): string
    {
        return $this->score >= Prospects::HOT ? 'hot' : ($this->score >= Prospects::WARM ? 'warm' : 'cold');
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
