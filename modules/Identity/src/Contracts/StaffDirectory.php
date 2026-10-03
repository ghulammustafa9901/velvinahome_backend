<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use Modules\Identity\Data\OwnerAccountData;

/**
 * What other modules and installation tooling may ask of staff accounts.
 */
interface StaffDirectory
{
    /**
     * Create the store's owner account if it does not exist, with the Owner role. Returns a
     * one-time temporary password only when the account was created now.
     */
    public function ensureOwner(string $name, string $email): OwnerAccountData;
}
