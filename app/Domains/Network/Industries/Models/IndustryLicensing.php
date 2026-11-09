<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Models;

use App\Domains\Network\Locations\Models\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $industry_id
 * @property int $state_id
 * @property bool $required
 * @property ?string $issuing_body
 * @property ?string $number_pattern
 * @property ?string $lookup_url
 * @property ?string $notes
 * @property-read State $state
 */
final class IndustryLicensing extends Model
{
    protected $table = 'industry_licensing';

    protected $fillable = ['needs_review', 'industry_id', 'state_id', 'required', 'issuing_body', 'number_pattern', 'lookup_url', 'notes'];

    protected $attributes = ['needs_review' => false, 'required' => true, 'issuing_body' => null, 'number_pattern' => null, 'lookup_url' => null, 'notes' => null];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'needs_review' => 'boolean'];
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function accepts(string $number): bool
    {
        return $this->number_pattern === null || $this->number_pattern === '' || preg_match('/^(?:'.$this->number_pattern.')$/i', trim($number)) === 1;
    }
}
