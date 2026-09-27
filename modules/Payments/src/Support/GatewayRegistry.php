<?php

declare(strict_types=1);

namespace Modules\Payments\Support;

use Modules\Payments\Contracts\PaymentGateway;

/**
 * Online gateway adapters by provider code. Empty until the first gateway is chosen.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->code()] = $gateway;
    }

    public function get(string $code): ?PaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }
}
