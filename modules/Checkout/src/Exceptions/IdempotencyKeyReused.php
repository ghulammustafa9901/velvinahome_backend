<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use DomainException;

/**
 * Maps to `409 idempotency_key_reused`: the key already placed an order with a different
 * request body. A retry must send the same body; a new order needs a new key.
 */
final class IdempotencyKeyReused extends DomainException
{
    public static function make(): self
    {
        return new self('This Idempotency-Key was already used for a different order request.');
    }
}
