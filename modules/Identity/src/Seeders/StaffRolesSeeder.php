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
 * Seeds the permissions and the six default roles. Runs at provisioning and after every
 * `tenants:migrate`, so it converges without undoing a brand's own changes:
 *
 *  - a role created now gets the full default matrix;
 *  - an existing role only gains permissions that did not exist before this run — a
 *    permission a brand deliberately removed from its Manager role stays removed.
 */
final class StaffRolesSeeder extends Seeder
{
    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        $newPermissions = [];

        foreach (StaffPermission::cases() as $permission) {
            $model = Permission::findOrCreate($permission->value, 'staff');

            if ($model->wasRecentlyCreated) {
                $newPermissions[] = $permission->value;
            }
        }

        foreach (StaffRole::cases() as $role) {
            $defaults = array_map(fn (StaffPermission $permission): string => $permission->value, $role->permissions());
            $model = Role::findOrCreate($role->value, 'staff');

            if ($model->wasRecentlyCreated) {
                $model->syncPermissions($defaults);

                continue;
            }

            $additions = array_values(array_intersect($defaults, $newPermissions));

            if ($additions !== []) {
                $model->givePermissionTo($additions);
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
