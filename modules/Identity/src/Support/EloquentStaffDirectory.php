<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Support\Str;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Identity\Data\OwnerAccountData;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Models\StaffUser;

final class EloquentStaffDirectory implements StaffDirectory
{
    public function ensureOwner(string $name, string $email): OwnerAccountData
    {
        $email = strtolower($email);
        $existing = StaffUser::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null) {
            $existing->assignRole(StaffRole::Owner->value);

            return new OwnerAccountData($existing->ulid, $existing->email, false, null);
        }

        $password = Str::password(20);

        $owner = StaffUser::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'status' => 'active',
        ]);

        $owner->assignRole(StaffRole::Owner->value);

        return new OwnerAccountData($owner->ulid, $owner->email, true, $password);
    }
}
