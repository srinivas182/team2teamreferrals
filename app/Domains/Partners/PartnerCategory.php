<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of business that can advertise (mortgage lenders, title and escrow…).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $singular
 * @property bool $licensed
 * @property ?string $license_label
 * @property ?string $license_lookup_url
 * @property string $schema_type
 * @property ?list<array{q: string, a: string}> $faq
 * @property int $sort
 * @property bool $is_active
 */
final class PartnerCategory extends Model
{
    /** Schema.org types a category may use (all LocalBusiness subtypes). */
    public const SCHEMA_TYPES = ['ProfessionalService', 'FinancialService', 'InsuranceAgency', 'LegalService', 'MovingCompany', 'HomeAndConstructionBusiness', 'LocalBusiness'];

    protected $fillable = ['slug', 'name', 'singular', 'licensed', 'license_label', 'license_lookup_url', 'schema_type', 'faq', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['licensed' => 'boolean', 'is_active' => 'boolean', 'faq' => 'array', 'sort' => 'integer'];
    }

    /** @param Builder<PartnerCategory> $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    /** The regulator's public lookup page for a license number, when the category has one. */
    public function lookupUrl(?string $number): ?string
    {
        if ($this->license_lookup_url === null || $number === null || $number === '') {
            return null;
        }

        return str_replace('{number}', rawurlencode($number), $this->license_lookup_url);
    }
}
