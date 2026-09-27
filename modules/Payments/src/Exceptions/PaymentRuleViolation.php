<?php

declare(strict_types=1);

namespace Modules\Payments\Exceptions;

use Illuminate\Validation\ValidationException;

final class PaymentRuleViolation
{
    public static function refundExceedsPaid(string $refundable): ValidationException
    {
        return ValidationException::withMessages(['amount' => __('At most :amount can be refunded.', ['amount' => $refundable])]);
    }

    public static function notAwaitingVerification(): ValidationException
    {
        return ValidationException::withMessages(['payment' => __('This payment is not waiting for verification.')]);
    }

    public static function noPayment(): ValidationException
    {
        return ValidationException::withMessages(['payment' => __('This order has no payment to act on.')]);
    }
}
