<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Enums\StaffPermission;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Models\StaffUser;
use Modules\Identity\Support\AuthorizesStaff;
use Modules\Support\Contracts\Action;

/**
 * Changes a staff member's name, role or status. Role changes are security events and are
 * logged (spec §9).
 */
final class UpdateStaffMember implements Action
{
    use AuthorizesStaff;

    public function handle(StaffUser $staff, StaffMemberData $data, StaffUser $actor): StaffUser
    {
        $this->authorizeStaff($actor, StaffPermission::StaffManage);

        $touchesOwner = $staff->hasRole(StaffRole::Owner->value) || $data->role === StaffRole::Owner;

        if ($touchesOwner && ! $actor->hasRole(StaffRole::Owner->value)) {
            throw new AuthorizationException('Only an owner can change an owner account.');
        }

        if ($staff->is($actor) && ($data->status !== 'active' || $data->role !== StaffRole::Owner) && $actor->hasRole(StaffRole::Owner->value)) {
            throw new AuthorizationException('Owners cannot demote or disable themselves.');
        }

        $previousRoles = $staff->getRoleNames()->all();

        $staff->forceFill(['name' => $data->name, 'status' => $data->status])->save();
        $staff->syncRoles([$data->role->value]);

        if ($previousRoles !== [$data->role->value]) {
            activity('security')->causedBy($actor)->performedOn($staff)->event('role_changed')
                ->withProperties(['from' => $previousRoles, 'to' => [$data->role->value]])
                ->log('Staff role changed');
        }

        return $staff;
    }
}
