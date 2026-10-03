<?php

declare(strict_types=1);

namespace Modules\Support\Modules;

use Modules\Support\Security\EncryptedColumn;

/**
 * What every module contributes to the database, collected in one place: default seeders,
 * append-only ledgers and encrypted columns. Each module declares its share through its
 * service provider; `app:migrate` and `secrets:reencrypt` read the totals from here, so they
 * never have to know which modules exist.
 */
final class ModuleRegistry
{
    /** @var array<string, list<string>> module => tables */
    private array $appendOnlyTables = [];

    /** @var array<string, list<class-string>> module => seeder classes */
    private array $defaultSeeders = [];

    /** @var list<EncryptedColumn> */
    private array $encryptedColumns = [];

    /** @var list<string> */
    private array $modules = [];

    /**
     * @param  list<string>  $appendOnlyTables
     * @param  list<class-string>  $defaultSeeders
     * @param  list<EncryptedColumn>  $encryptedColumns
     */
    public function register(
        string $module,
        array $appendOnlyTables,
        array $defaultSeeders,
        array $encryptedColumns = [],
    ): void {
        $this->modules[] = $module;
        array_push($this->encryptedColumns, ...$encryptedColumns);

        $this->appendOnlyTables[$module] = $appendOnlyTables;
        $this->defaultSeeders[$module] = $defaultSeeders;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return $this->modules;
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
     * Seeders that bring the store to its default state. Every one must be idempotent: they
     * run again after every migration run.
     *
     * @return list<class-string>
     */
    public function defaultSeeders(): array
    {
        return array_values(array_merge(...array_values($this->defaultSeeders) ?: [[]]));
    }

    /**
     * Columns holding APP_KEY ciphertext.
     *
     * @param  EncryptedColumn::CENTRAL|EncryptedColumn::TENANT  $scope
     * @return list<EncryptedColumn>
     */
    public function encryptedColumns(string $scope): array
    {
        return array_values(array_filter($this->encryptedColumns, fn (EncryptedColumn $column): bool => $column->scope === $scope));
    }
}
