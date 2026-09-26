<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\StockReservation;
use Modules\Support\Contracts\Action;

/**
 * The scheduled sweep. Only reservations for checkouts in progress (`cart`) ever expire: a
 * reservation attached to an order is settled by the order's own lifecycle, never by a timer
 * — that is what makes COD overselling impossible (ADR-0010).
 */
final class ExpireReservations implements Action
{
    public const EXPIRABLE_REFERENCE = 'cart';

    public function __construct(private readonly SettleReservation $settle) {}

    public function handle(): int
    {
        $expired = 0;

        $due = StockReservation::query()
            ->where('status', 'active')
            ->where('reference_type', self::EXPIRABLE_REFERENCE)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(1000)
            ->pluck('ulid');

        foreach ($due as $ulid) {
            try {
                $this->settle->handle((string) $ulid, 'expire');
                $expired++;
            } catch (InventoryRuleViolation) {
                // Settled concurrently (checkout completed at the last second): nothing to do.
            }
        }

        return $expired;
    }
}
