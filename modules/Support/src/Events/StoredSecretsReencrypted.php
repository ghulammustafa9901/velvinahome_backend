<?php

declare(strict_types=1);

namespace Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Support\Security\EncryptedColumn;

/**
 * Stored secrets of one database (the central one, or the current tenant's) were re-encrypted
 * under a new APP_KEY. Modules that cache ciphertext drop that cache, so nothing keeps a value
 * only the retiring key can read.
 */
final class StoredSecretsReencrypted
{
    use Dispatchable;

    /**
     * @param  EncryptedColumn::CENTRAL|EncryptedColumn::TENANT  $scope
     */
    public function __construct(public readonly string $scope) {}
}
