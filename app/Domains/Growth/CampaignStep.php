<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email in a campaign, sent `wait_hours` after the previous one (or after enrolling).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $position
 * @property int $wait_hours
 * @property string $subject
 * @property ?string $subject_b
 * @property ?string $winner
 * @property string $body
 * @property ?string $cta_label
 * @property ?string $cta_url
 * @property-read Campaign $campaign
 */
final class CampaignStep extends Model
{
    protected $fillable = ['campaign_id', 'position', 'wait_hours', 'subject', 'subject_b', 'body', 'cta_label', 'cta_url'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'wait_hours' => 'integer'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
