<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Identity\Data\OwnerAccountData;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Models\StaffUser;

final class EloquentStaffDirectory implements StaffDirectory
{
    public function __construct(private readonly AuthFactory $auth) {}

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

    public function findUlidByEmail(string $email): ?string
    {
        return StaffUser::query()->whereRaw('lower(email) = ?', [strtolower($email)])->value('ulid');
    }

    public function isActive(string $staffUlid): bool
    {
        return StaffUser::query()->where('ulid', $staffUlid)->where('status', 'active')->exists();
    }

    public function startSession(string $staffUlid): bool
    {
        $staff = StaffUser::query()->where('ulid', $staffUlid)->where('status', 'active')->first();

        if ($staff === null) {
            return false;
        }

        $this->auth->guard('staff')->login($staff);

        return true;
    }

    public function recordAccessEvent(string $event, string $description, array $properties): void
    {
        activity('access')
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
