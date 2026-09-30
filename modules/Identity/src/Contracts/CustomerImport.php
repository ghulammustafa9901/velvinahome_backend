<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * Customer records migrated from another store. They get no password: the customer claims
 * the account by registering or resetting the password with the same email.
 */
interface CustomerImport
{
    /**
     * Find the customer by email or create one. An existing customer keeps what they entered
     * themselves; only empty name and phone fields are filled in. Marketing consent is never
     * imported as given — it stays off until the customer opts in here.
     *
     * @return int customer id (for the Customers address book)
     */
    public function upsert(string $email, ?string $firstName, ?string $lastName, ?string $phone): int;
}
