<?php

declare(strict_types=1);

namespace App\Domains\Identity;

use App\Domains\Platform\Settings\Settings;

/**
 * Whether admin staff must use two-step sign-in to open the admin area. Required by default (STAFF_TWO_FACTOR);
 * a super admin can turn it off for a demo in Admin → System → General, and back on before going live.
 */
final class StaffTwoFactor
{
    public static function required(): bool
    {
        $saved = app(Settings::class)->get('security.staff_two_factor');

        return $saved === null ? (bool) config('app.staff_two_factor', true) : $saved === '1';
    }
}
