<?php

declare(strict_types=1);

namespace App\Domains\Community\Directory;

/** What a team focuses on (by industry family) and the languages it serves clients in. */
final class Specialties
{
    public const BY_FAMILY = [
        'property' => ['Buyers', 'Sellers', 'Luxury', 'First-time buyers', 'Investors', 'New construction', 'Relocation', 'Commercial', 'Land and farms', 'Rentals'],
        'money' => ['Purchase loans', 'Refinancing', 'First-time buyers', 'VA loans', 'FHA loans', 'Jumbo loans', 'Self-employed', 'Retirement planning', 'Tax', 'Business finance'],
        'home' => ['Residential', 'Commercial', 'Repairs', 'Remodels', 'New installs', 'Emergency service', 'Energy efficiency', 'Inspections', 'Maintenance plans'],
        'legal' => ['Family', 'Estate planning', 'Real estate', 'Business', 'Personal injury', 'Immigration', 'Employment', 'Criminal defense', 'Bankruptcy'],
        'care' => ['Seniors', 'Memory care', 'Home care', 'Hospice', 'Children', 'Disability support', 'Short-term care', 'Long-term care'],
        'life' => ['Families', 'Seniors', 'Pets', 'Events', 'Wellness', 'Education', 'Moving'],
        'business' => ['Startups', 'Small business', 'Enterprise', 'Marketing', 'Technology', 'Hiring', 'Operations'],
        'vehicles' => ['Sales', 'Leasing', 'Repairs', 'Insurance claims', 'Fleet', 'Classic cars', 'Electric vehicles'],
    ];

    public const LANGUAGES = ['English', 'Spanish', 'Chinese', 'Tagalog', 'Vietnamese', 'Arabic', 'French', 'Korean', 'Russian', 'German', 'Haitian Creole', 'Hindi', 'Portuguese', 'Italian', 'Polish', 'Urdu', 'Japanese', 'Persian', 'Gujarati', 'Telugu', 'Bengali', 'Punjabi', 'Tamil', 'American Sign Language'];

    /** @return list<string> the specialties for every industry family this team holds a seat in */
    public static function forTeam(int $teamId): array
    {
        $families = \Illuminate\Support\Facades\DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->where('seats.primary_team_id', $teamId)->distinct()->pluck('industries.family')->all();
        $out = [];
        foreach ($families ?: [null] as $family) {
            $out = [...$out, ...self::forFamily(is_string($family) ? $family : null)];
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function forFamily(?string $family): array
    {
        return [...(self::BY_FAMILY[$family] ?? []), 'Other'];
    }
}
