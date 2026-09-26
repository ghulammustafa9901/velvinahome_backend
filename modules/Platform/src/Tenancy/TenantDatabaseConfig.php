<?php

declare(strict_types=1);

namespace Modules\Platform\Tenancy;

use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantDatabase;
use Stancl\Tenancy\DatabaseConfig;

/**
 * Connection details for one tenant, read from the encrypted landlord record instead of
 * stancl/tenancy's internal `data` keys.
 *
 * Two connections exist per tenant and they map to two different PostgreSQL roles:
 *
 *  - connection():         the application role — DML only, what every request uses;
 *  - migratorConnection(): the schema owner — used only by `tenants:migrate`.
 *
 * Keeping them apart is what makes "the application cannot alter its own schema, and cannot
 * rewrite a ledger" a property of the database rather than of the code.
 */
final class TenantDatabaseConfig extends DatabaseConfig
{
    public function __construct(Tenant $tenant, private readonly TenantDatabase $credentials)
    {
        parent::__construct($tenant);
    }

    public function getName(): string
    {
        return $this->credentials->database;
    }

    public function getUsername(): string
    {
        return $this->credentials->app_username;
    }

    public function getPassword(): string
    {
        return $this->credentials->app_password;
    }

    public function getTemplateConnectionName(): string
    {
        return 'tenant_template';
    }

    /**
     * @return array<string, mixed>
     */
    public function connection(): array
    {
        return $this->build($this->credentials->app_username, $this->credentials->app_password);
    }

    /**
     * @return array<string, mixed>
     */
    public function migratorConnection(): array
    {
        return $this->build($this->credentials->migration_username, $this->credentials->migration_password);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $username, string $password): array
    {
        /** @var array<string, mixed> $template */
        $template = config('database.connections.tenant_template');

        return array_merge($template, [
            'host' => $this->credentials->host,
            'port' => $this->credentials->port,
            'database' => $this->credentials->database,
            'username' => $username,
            'password' => $password,
        ]);
    }
}
