<?php

declare(strict_types=1);

namespace Modules\Pricing\Exceptions;

use DomainException;

/**
 * Maps to `409 coupon_unavailable`: the coupon was valid when quoted but not any more (for
 * example its last use was taken by a concurrent order).
 */
final class CouponUnavailable extends DomainException
{
    public static function because(string $code, string $reason): self
    {
        return new self("Coupon {$code} can no longer be used: {$reason}.");
    }
}
