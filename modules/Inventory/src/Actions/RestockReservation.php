<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Support\Ledger;
use Modules\Inventory\Support\Posting;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Contracts\Action;

/**
 * Returns committed (sold) stock to the location it was sold from, as a `return` movement
 * with a mandatory reason (ADR-0010: stock never reappears silently).
 */
final class RestockReservation implements Action
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $reservationUlid, int $quantity, string $reason, string $orderUlid): void
    {
        if ($quantity < 1) {
            throw InventoryRuleViolation::nonPositiveQuantity();
        }

        $this->db->connection()->transaction(function () use ($reservationUlid, $quantity, $reason, $orderUlid): void {
            $reservation = StockReservation::query()->where('ulid', $reservationUlid)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'converted') {
                throw InventoryRuleViolation::reservationNotActive($reservation->ulid, $reservation->status);
            }

            $level = $this->ledger->lock([[$reservation->variant_id, $reservation->location_id]])[$reservation->variant_id.':'.$reservation->location_id];

            $this->ledger->post($level, $this->resolve->variantById($reservation->variant_id), MovementType::Return, min($quantity, $reservation->quantity), new Posting(
                referenceType: 'order',
                referenceUlid: $orderUlid,
                reason: $reason,
            ));
        });
    }
}
