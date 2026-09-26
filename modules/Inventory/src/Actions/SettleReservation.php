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
 * Ends an active reservation, one of three ways:
 *
 *  - `commit`:  converted to a `sale` movement (payment confirmed, or a COD order placed —
 *               ADR-0010). on_hand and reserved drop together.
 *  - `release`: the checkout failed or was cancelled; reserved drops, on_hand is untouched.
 *  - `expire`:  as release, by the scheduled sweep.
 *
 * `converted`, `released` and `expired` are terminal: a reservation can be settled once.
 */
final class SettleReservation implements Action
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $reservationUlid, string $outcome, ?string $orderUlid = null): StockReservation
    {
        return $this->db->connection()->transaction(function () use ($reservationUlid, $outcome, $orderUlid): StockReservation {
            $reservation = StockReservation::query()->where('ulid', $reservationUlid)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'active') {
                throw InventoryRuleViolation::reservationNotActive($reservation->ulid, $reservation->status);
            }

            $level = $this->ledger->lock([[$reservation->variant_id, $reservation->location_id]])[$reservation->variant_id.':'.$reservation->location_id];
            $this->ledger->adjustReserved($level, -$reservation->quantity);

            if ($outcome === 'commit') {
                $variant = $this->resolve->variantById($reservation->variant_id);

                $this->ledger->post($level, $variant, MovementType::Sale, -$reservation->quantity, new Posting(
                    referenceType: $orderUlid === null ? $reservation->reference_type : 'order',
                    referenceUlid: $orderUlid ?? $reservation->reference_ulid,
                ));
            }

            $reservation->forceFill(match ($outcome) {
                'commit' => ['status' => 'converted', 'converted_at' => now()],
                'expire' => ['status' => 'expired', 'released_at' => now()],
                default => ['status' => 'released', 'released_at' => now()],
            })->save();

            return $reservation;
        });
    }
}
