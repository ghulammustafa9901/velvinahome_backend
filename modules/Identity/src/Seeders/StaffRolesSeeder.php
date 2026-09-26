<?php

declare(strict_types=1);

namespace Modules\Identity\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Enums\StaffPermission;
use Modules\Identity\Enums\StaffRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every permission and the six default roles into the current tenant. Idempotent:
 * re-running converges the roles onto the matrix in StaffRole without duplicating rows.
 */
final class StaffRolesSeeder extends Seeder
{
    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        foreach (StaffPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'staff');
        }

        foreach (StaffRole::cases() as $role) {
            Role::findOrCreate($role->value, 'staff')->syncPermissions(
                array_map(fn (StaffPermission $permission): string => $permission->value, $role->permissions()),
            );
        }

        $registrar->forgetCachedPermissions();
    }
}
