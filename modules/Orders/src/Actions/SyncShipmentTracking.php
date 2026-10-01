<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Shipment;
use Modules\Shipping\Contracts\Couriers;
use Modules\Support\Contracts\Action;
use Throwable;

/**
 * Ask couriers with a tracking integration about parcels still in transit (shipped in the last
 * 30 days) and record deliveries and returns exactly as staff would. Couriers without an
 * integration are left to staff; a courier that cannot answer is simply asked again later.
 */
final class SyncShipmentTracking implements Action
{
    public const BATCH = 200;

    public function __construct(
        private readonly Couriers $couriers,
        private readonly UpdateShipment $update,
    ) {}

    /**
     * @return array{checked: int, delivered: int, returned: int}
     */
    public function handle(): array
    {
        $stats = ['checked' => 0, 'delivered' => 0, 'returned' => 0];

        $shipments = Shipment::query()
            ->where('status', 'in_transit')
            ->whereNotNull('tracking_number')
            ->where('shipped_at', '>=', Carbon::now()->subDays(30))
            ->orderBy('shipped_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($shipments as $shipment) {
            try {
                $tracking = $this->couriers->track($shipment->courier, (string) $shipment->tracking_number);
            } catch (Throwable $e) {
                Log::warning('Courier tracking failed', ['shipment' => $shipment->ulid, 'exception' => $e::class]);

                continue;
            }

            if ($tracking === null) {
                continue;
            }

            $stats['checked']++;

            if (in_array($tracking->status, ['delivered', 'returned'], true)) {
                $this->update->handle($shipment->ulid, $tracking->status, $tracking->detail ?? 'Reported by the courier');
                $stats[$tracking->status]++;
            }
        }

        return $stats;
    }
}
