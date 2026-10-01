<?php

declare(strict_types=1);

namespace Modules\Shipping\Support;

use Modules\Shipping\Contracts\CourierAdapter;
use Modules\Shipping\Data\CourierTracking;

/**
 * Booked by staff at the courier's counter or portal: tracking numbers are typed in and the
 * delivery outcome is recorded by hand.
 */
final class ManualCourier implements CourierAdapter
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual (tracking number typed in)';
    }

    public function tracks(): bool
    {
        return false;
    }

    public function track(string $trackingNumber, array $credentials): CourierTracking
    {
        return new CourierTracking('unknown');
    }
}
