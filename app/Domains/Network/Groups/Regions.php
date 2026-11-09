<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups;

/** US Census Bureau regions, used to split a full network into regional groups. */
final class Regions
{
    public const ALL = [
        'northeast' => ['label' => 'Northeast', 'states' => ['CT', 'ME', 'MA', 'NH', 'RI', 'VT', 'NJ', 'NY', 'PA']],
        'midwest' => ['label' => 'Midwest', 'states' => ['IL', 'IN', 'MI', 'OH', 'WI', 'IA', 'KS', 'MN', 'MO', 'NE', 'ND', 'SD']],
        'south' => ['label' => 'South', 'states' => ['DE', 'DC', 'FL', 'GA', 'MD', 'NC', 'SC', 'VA', 'WV', 'AL', 'KY', 'MS', 'TN', 'AR', 'LA', 'OK', 'TX']],
        'west' => ['label' => 'West', 'states' => ['AZ', 'CO', 'ID', 'MT', 'NV', 'NM', 'UT', 'WY', 'AK', 'CA', 'HI', 'OR', 'WA']],
    ];
}
