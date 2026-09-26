<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Support\PlatformAudit;
use Modules\Support\Contracts\Action;

/**
 * Adds a member of the Platform Owner's team. Two-factor enrolment is forced at first
 * sign-in by the platform panel.
 */
final class CreatePlatformUser implements Action
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @return array{user: PlatformUser, password: string|null} the password only when generated here
     */
    public function handle(string $name, string $email, PlatformRole $role, ?string $password = null): array
    {
        $generated = $password === null ? Str::password(20) : null;
        $password ??= $generated;

        $validator = validator(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)]],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $user = PlatformUser::query()->create([
            'name' => $name,
            'email' => strtolower($email),
            'password' => $password,
            'role' => $role,
            'status' => 'active',
        ]);

        $this->audit->record('platform_user.created', subjectType: 'platform_user', subjectId: $user->ulid, properties: ['role' => $role->value]);

        return ['user' => $user, 'password' => $generated];
    }
}
