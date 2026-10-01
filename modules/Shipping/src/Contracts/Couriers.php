<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Shipping\Data\CourierTracking;

/**
 * What Orders may ask of Shipping about couriers. A shipment names its courier by the code or
 * the name staff picked (older shipments carry free text).
 */
interface Couriers
{
    /**
     * @return array<string, string> enabled couriers, code => name
     */
    public function options(): array;

    /**
     * The public tracking link for a shipment, when the courier has a link template.
     */
    public function trackingUrl(?string $courier, ?string $trackingNumber): ?string;

    /**
     * Ask the courier's adapter for the shipment's status; null when the courier has no
     * tracking adapter.
     */
    public function track(?string $courier, string $trackingNumber): ?CourierTracking;
}
