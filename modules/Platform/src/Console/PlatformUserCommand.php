<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\CreatePlatformUser;
use Modules\Platform\Enums\PlatformRole;

final class PlatformUserCommand extends Command
{
    protected $signature = 'platform:user {email} {name} {--role=engineer : owner, engineer or support}';

    protected $description = 'Create a platform (landlord panel) user; a password is generated and shown once';

    public function handle(CreatePlatformUser $createUser): int
    {
        $result = $createUser->handle(
            (string) $this->argument('name'),
            (string) $this->argument('email'),
            PlatformRole::from((string) $this->option('role')),
        );

        $this->components->info(sprintf('Platform user [%s] created.', $result['user']->email));

        if ($result['password'] !== null) {
            $this->components->warn('Password (shown once): '.$result['password']);
        }

        return self::SUCCESS;
    }
}
