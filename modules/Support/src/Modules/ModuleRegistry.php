<?php

declare(strict_types=1);

namespace Modules\Support\Modules;

use Modules\Support\Security\EncryptedColumn;

/**
 * What every module contributes to a tenant database, collected in one place.
 *
 * Provisioning and `tenants:migrate` live in the Platform module, but the tables, seeders and
 * append-only ledgers belong to the modules that own them. Each module declares its share
 * through its service provider; Platform reads the totals from here. That keeps the
 * dependency pointing the right way — Platform never has to know which modules exist.
 */
final class ModuleRegistry
{
    /** @var array<string, string> module => absolute path */
    private array $tenantMigrationPaths = [];

    /** @var array<string, string> module => absolute path */
    private array $landlordMigrationPaths = [];

    /** @var array<string, list<string>> module => tables */
    private array $appendOnlyTables = [];

    /** @var array<string, list<class-string>> module => seeder classes */
    private array $tenantSeeders = [];

    /** @var list<EncryptedColumn> */
    private array $encryptedColumns = [];

    /** @var list<string> */
    private array $modules = [];

    /**
     * @param  list<string>  $appendOnlyTables
     * @param  list<class-string>  $tenantSeeders
     * @param  list<EncryptedColumn>  $encryptedColumns
     */
    public function register(
        string $module,
        ?string $tenantMigrationPath,
        ?string $landlordMigrationPath,
        array $appendOnlyTables,
        array $tenantSeeders,
        array $encryptedColumns = [],
    ): void {
        $this->modules[] = $module;
        array_push($this->encryptedColumns, ...$encryptedColumns);

        if ($tenantMigrationPath !== null) {
            $this->tenantMigrationPaths[$module] = $tenantMigrationPath;
        }

        if ($landlordMigrationPath !== null) {
            $this->landlordMigrationPaths[$module] = $landlordMigrationPath;
        }

        $this->appendOnlyTables[$module] = $appendOnlyTables;
        $this->tenantSeeders[$module] = $tenantSeeders;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * @return list<string>
     */
    public function tenantMigrationPaths(): array
    {
        return array_values($this->tenantMigrationPaths);
    }

    /**
     * @return list<string>
     */
    public function landlordMigrationPaths(): array
    {
        return array_values($this->landlordMigrationPaths);
    }

    /**
     * Tables whose rows may be inserted and read but never updated or deleted by the
     * application role (spec §6.3). Enforced by revoking privileges after every migration.
     *
     * @return list<string>
     */
    public function appendOnlyTables(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->appendOnlyTables) ?: [[]])));
    }

    /**
     * Seeders that bring a fresh tenant database to its default state. Every one must be
     * idempotent: provisioning re-runs them after a partial failure.
     *
     * @return list<class-string>
     */
    public function tenantSeeders(): array
    {
        return array_values(array_merge(...array_values($this->tenantSeeders) ?: [[]]));
    }

    /**
     * Columns holding APP_KEY ciphertext, in the central database or in every tenant database.
     *
     * @param  EncryptedColumn::CENTRAL|EncryptedColumn::TENANT  $scope
     * @return list<EncryptedColumn>
     */
    public function encryptedColumns(string $scope): array
    {
        return array_values(array_filter($this->encryptedColumns, fn (EncryptedColumn $column): bool => $column->scope === $scope));
    }
}
