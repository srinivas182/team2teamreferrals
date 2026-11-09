<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Templates;

/**
 * Starting forms and pipelines per industry family. Industries inherit these until they customise.
 * Every form has the shared core (client, contact, timeline, notes); families add what their pros need.
 *
 * Legal and care forms deliberately have no estimated-value field: a dollar value on a legal or
 * healthcare referral would suggest the referral is being paid for, which those tiers must avoid.
 */
final class FamilyDefaults
{
    /** @return list<array<string, mixed>> */
    public static function form(string $family): array
    {
        $f = fn (string $key, string $label, string $type, bool $required = false, array $extra = []) => ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $required, 'sensitive' => false, ...$extra];
        $core = [
            $f('client_name', 'Client name', 'text', true, ['sensitive' => true]),
            $f('client_phone', 'Client mobile', 'text', true, ['sensitive' => true]),
            $f('client_email', 'Client email', 'text', false, ['sensitive' => true]),
            $f('timeline', 'When do they want to start?', 'choice', true, ['role' => 'timeline', 'options' => ['Within 30 days', '1–3 months', '3–12 months', 'Just exploring']]),
        ];
        $extra = match ($family) {
            'property' => [
                $f('side', 'Buying or selling?', 'choice', true, ['options' => ['Buying', 'Selling', 'Both', 'Renting']]),
                $f('price_range', 'Price range', 'money_range', true, ['role' => 'value']),
                $f('pre_approved', 'Pre-approved for a mortgage?', 'boolean'),
                $f('areas', 'Neighbourhoods or areas', 'text'),
            ],
            'home' => [
                $f('service', 'What do they need?', 'text', true),
                $f('project_budget', 'Budget', 'money_range', false, ['role' => 'value']),
                $f('owns_home', 'Do they own the home?', 'boolean', true),
                $f('property_type', 'Property type', 'choice', false, ['options' => ['Single-family', 'Townhouse', 'Condo', 'Multi-family', 'Commercial']]),
            ],
            'money' => [
                $f('need', 'What do they need help with?', 'multi_choice', true, ['options' => ['Home', 'Auto', 'Life', 'Health', 'Retirement', 'Investments', 'Taxes', 'Mortgage']]),
                $f('estimated_value', 'Estimated size (loan, premium or assets)', 'money_range', false, ['role' => 'value']),
                $f('existing_provider', 'Do they have a current provider?', 'boolean'),
            ],
            'legal' => [
                $f('matter', 'Type of matter', 'text', true),
                $f('urgent_deadline', 'Is there a court or filing deadline?', 'date'),
            ],
            'care' => [
                $f('care_for', 'Who is the care for?', 'choice', true, ['options' => ['Self', 'Parent', 'Spouse', 'Other family']]),
                $f('care_type', 'Type of care', 'text', true),
                $f('payer', 'How will care be paid for?', 'choice', false, ['options' => ['Private pay', 'Long-term care insurance', 'Medicare', 'Medicaid', 'Not sure']]),
            ],
            'vehicles' => [
                $f('looking_for', 'What are they looking for?', 'text', true),
                $f('budget', 'Budget', 'money_range', false, ['role' => 'value']),
                $f('trade_in', 'Trading something in?', 'boolean'),
            ],
            'business' => [
                $f('company', 'Company name', 'text', true),
                $f('need', 'What do they need?', 'text', true),
                $f('deal_size', 'Estimated deal size', 'money_range', false, ['role' => 'value']),
                $f('employees', 'Number of employees', 'number'),
            ],
            default => [
                $f('need', 'What do they need?', 'text', true),
                $f('budget', 'Budget', 'money_range', false, ['role' => 'value']),
                $f('event_date', 'Date', 'date'),
            ],
        };

        return [...$core, ...$extra, $f('notes', 'Anything else the pro should know?', 'textarea')];
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public static function stages(string $family): array
    {
        $s = fn (string $key, string $label, string $kind = 'open') => ['key' => $key, 'label' => $label, 'kind' => $kind];
        $middle = match ($family) {
            'property' => [$s('showing', 'Showing or listing appointment'), $s('under_contract', 'Under contract')],
            'home' => [$s('estimate', 'Estimate visit'), $s('proposal', 'Proposal sent'), $s('scheduled', 'Job scheduled')],
            'money' => [$s('needs_review', 'Needs review'), $s('application', 'Application or quote')],
            'legal' => [$s('consultation', 'Consultation'), $s('retained', 'Retained')],
            'care' => [$s('assessment', 'Assessment'), $s('placement', 'Placement or start of care')],
            'vehicles' => [$s('visit', 'Visit or demo'), $s('offer', 'Offer')],
            'business' => [$s('discovery', 'Discovery call'), $s('proposal', 'Proposal'), $s('negotiation', 'Negotiation')],
            default => [$s('consultation', 'Consultation'), $s('booked', 'Booked')],
        };

        return [$s('new', 'New'), $s('contacted', 'Contacted'), ...$middle, $s('won', 'Won', 'won'), $s('lost', 'Lost', 'lost')];
    }
}
