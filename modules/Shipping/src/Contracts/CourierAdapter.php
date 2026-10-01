<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Shipping\Data\CourierTracking;

/**
 * One courier integration (spec §6.7). Adapters are registered by key; a brand's courier row
 * names its adapter and holds its own encrypted credentials. The first live adapter arrives
 * with a courier account; until then every courier is "manual".
 */
interface CourierAdapter
{
    public function key(): string;

    public function label(): string;

    /**
     * Whether `track()` can report a shipment's status.
     */
    public function tracks(): bool;

    /**
     * The shipment's current status at the courier.
     *
     * @param  array<string, string>  $credentials  the brand's own, decrypted
     */
    public function track(string $trackingNumber, array $credentials): CourierTracking;
}
