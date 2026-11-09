<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Catalogue;

/**
 * Starter licensing suggestions for industries with a national lookup, to save counsel time.
 * Every row is created as "needs review" and never counts as coverage until an admin confirms it.
 */
final class LicensingStarter
{
    /** @return array<string, array{body: string, lookup: string, note: string}> industry slug => template ({state} is replaced) */
    public static function templates(): array
    {
        $re = ['body' => '{state} real estate licensing authority', 'lookup' => 'https://www.arello.com/', 'note' => 'ARELLO license verification covers most states; confirm the state commission and its own lookup.'];
        $ins = ['body' => '{state} department of insurance', 'lookup' => 'https://content.naic.org/state-insurance-departments', 'note' => 'Confirm the department and its producer license lookup from the NAIC directory.'];

        return [
            'real-estate' => $re,
            'commercial-real-estate' => $re,
            'land' => $re,
            'ranch-farm' => $re,
            'property-management' => [...$re, 'note' => 'Most states license property managers as real estate brokers; confirm the state rule.'],
            'mortgage' => ['body' => '{state} mortgage regulator (NMLS)', 'lookup' => 'https://www.nmlsconsumeraccess.org/', 'note' => 'Mortgage loan originators are listed in NMLS Consumer Access; confirm the state regulator.'],
            'insurance' => $ins,
            'medicare' => [...$ins, 'note' => 'Medicare agents need a state health/life license; carrier appointment and certification are separate.'],
        ];
    }
}
