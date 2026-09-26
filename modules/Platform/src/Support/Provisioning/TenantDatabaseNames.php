<?php

declare(strict_types=1);

namespace Modules\Platform\Support\Provisioning;

use InvalidArgumentException;

/**
 * The database and role names for one tenant, derived from its slug.
 *
 * The slug is validated as `[a-z0-9]+(-[a-z0-9]+)*` at the landlord (a CHECK constraint)
 * and again here, so every identifier this produces is safe to quote into DDL — which has
 * no parameter binding for identifiers.
 */
final readonly class TenantDatabaseNames
{
    private const MAX_IDENTIFIER_LENGTH = 63;

    private function __construct(
        public string $database,
        public string $appRole,
        public string $migratorRole,
    ) {}

    public static function forSlug(string $slug): self
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new InvalidArgumentException("Invalid tenant slug [{$slug}].");
        }

        $base = str_replace('-', '_', $slug);
        $databasePrefix = (string) config('platform.provisioning.database_prefix');
        $rolePrefix = (string) config('platform.provisioning.role_prefix');

        $names = new self(
            database: $databasePrefix.$base,
            appRole: $rolePrefix.$base.'_app',
            migratorRole: $rolePrefix.$base.'_migrator',
        );

        foreach ([$names->database, $names->appRole, $names->migratorRole] as $identifier) {
            if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH || preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
                throw new InvalidArgumentException("Derived identifier [{$identifier}] is not a safe PostgreSQL identifier.");
            }
        }

        return $names;
    }
}
