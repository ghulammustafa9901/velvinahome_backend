<?php

declare(strict_types=1);

namespace Modules\Support\Contracts;

/**
 * Moves every declared stored secret to the current APP_KEY (key rotation).
 */
interface ReencryptsStoredSecrets
{
    /**
     * @return array<string, array{reencrypted: int, current: int, unreadable: int}> table.column => counts
     */
    public function handle(): array;
}
