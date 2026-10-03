<?php

declare(strict_types=1);

namespace Modules\Support\Contracts;

use Modules\Support\Security\EncryptedColumn;

/**
 * Moves the secrets stored in one database to the current APP_KEY (key rotation). Which
 * database a scope lives in is the caller's business: Support knows only the declared columns.
 */
interface ReencryptsStoredSecrets
{
    /**
     * @param  EncryptedColumn::CENTRAL|EncryptedColumn::TENANT  $scope
     * @return array<string, array{reencrypted: int, current: int, unreadable: int}> table.column => counts
     */
    public function handle(string $scope, string $connection): array;
}
