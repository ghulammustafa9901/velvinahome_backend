<?php

declare(strict_types=1);

namespace Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stored secrets were re-encrypted under a new APP_KEY. Modules that cache ciphertext drop that
 * cache, so nothing keeps a value only the retiring key can read.
 */
final class StoredSecretsReencrypted
{
    use Dispatchable;
}
