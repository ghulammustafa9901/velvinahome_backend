<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Enums\StaffPermission;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Models\StaffUser;
use Modules\Identity\Support\AuthorizesStaff;
use Modules\Support\Contracts\Action;

/**
 * Adds a staff member to the current brand with one role and a one-time password. They must
 * enrol two-factor authentication at first sign-in.
 */
final class CreateStaffMember implements Action
{
    use AuthorizesStaff;

    /**
     * @return array{staff: StaffUser, password: string}
     */
    public function handle(StaffMemberData $data, StaffUser $actor): array
    {
        $this->authorizeStaff($actor, StaffPermission::StaffManage);

        // Only an Owner may create another Owner.
        if ($data->role === StaffRole::Owner && ! $actor->hasRole(StaffRole::Owner->value)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only an owner can add another owner.');
        }

        $email = strtolower($data->email);

        if (StaffUser::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('A staff member with this email already exists.')]);
        }

        $password = Str::password(20);

        $staff = StaffUser::query()->create([
            'name' => $data->name,
            'email' => $email,
            'password' => $password,
            'status' => $data->status,
        ]);

        $staff->assignRole($data->role->value);

        activity('staff')->causedBy($actor)->performedOn($staff)->event('created')
            ->withProperties(['role' => $data->role->value])
            ->log('Staff member added');

        return ['staff' => $staff, 'password' => $password];
    }
}
