<?php

declare(strict_types=1);

namespace Modules\Pricing\Contracts;

use Modules\Pricing\Data\PricedCart;
use Modules\Pricing\Data\PricingRequest;

/**
 * The cart pricing pipeline (spec §6.4). Storefronts never compute a business value
 * (spec §3.4.2): every cart view, quote and order goes through here.
 */
interface CartPricer
{
    public function price(PricingRequest $request): PricedCart;
}
