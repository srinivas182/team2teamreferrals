<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Authorization\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: creates any missing roles and permissions. Runs on every deploy via a migration. */
final class SyncPermissions extends Command
{
    protected $signature = 't2t:permissions:sync';

    protected $description = 'Create the platform roles and the admin permission catalogue';

    public function handle(PermissionRegistrar $registrar): int
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach ([Permissions::SUPER_ADMIN, Permissions::STAFF, Permissions::MEMBER] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $registrar->forgetCachedPermissions();
        $this->info(count(Permissions::all()).' permissions and 3 roles in place.');

        return self::SUCCESS;
    }
}
