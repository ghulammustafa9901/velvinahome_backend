<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Actions\ImportCustomer;
use Modules\Identity\Contracts\CustomerImport;

final class ActionCustomerImport implements CustomerImport
{
    public function __construct(private readonly ImportCustomer $import) {}

    public function upsert(string $email, ?string $firstName, ?string $lastName, ?string $phone): int
    {
        return $this->import->handle($email, $firstName, $lastName, $phone)->id;
    }
}
