<?php

declare(strict_types=1);

namespace Modules\Support\Security;

/**
 * A column holding values encrypted with APP_KEY. Modules declare theirs
 * (ModuleServiceProvider::encryptedColumns) so `secrets:reencrypt` can move every stored
 * secret to a new key and the old key can be retired (docs/runbooks/rotate-secrets.md).
 *
 * Scope: the central database (only the Platform module has tables there) or every tenant
 * database.
 */
final readonly class EncryptedColumn
{
    public const CENTRAL = 'central';

    public const TENANT = 'tenant';

    /**
     * @param  array<string, bool|int|string>  $where  only rows matching these (column => value)
     */
    private function __construct(
        public string $scope,
        public string $table,
        public string $column,
        public bool $jsonEncoded = false,
        public array $where = [],
    ) {}

    public static function tenant(string $table, string $column): self
    {
        return new self(self::TENANT, $table, $column);
    }

    public static function central(string $table, string $column): self
    {
        return new self(self::CENTRAL, $table, $column);
    }

    /**
     * The ciphertext is stored as a JSON string (a `json` cast column).
     */
    public function json(): self
    {
        return new self($this->scope, $this->table, $this->column, true, $this->where);
    }

    public function where(string $column, bool|int|string $value): self
    {
        return new self($this->scope, $this->table, $this->column, $this->jsonEncoded, [...$this->where, $column => $value]);
    }

    public function key(): string
    {
        return $this->table.'.'.$this->column;
    }
}
