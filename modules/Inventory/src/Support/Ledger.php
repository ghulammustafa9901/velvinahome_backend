<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Catalog\Data\VariantReference;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Events\LowStockReached;
use Modules\Inventory\Events\StockChanged;
use Modules\Inventory\Exceptions\InsufficientStock;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Models\StockLevel;

/**
 * The only code path that changes `stock_levels.on_hand` (spec §6.5 rule 1).
 *
 * Every posting happens inside the caller's transaction, on a row locked with
 * `SELECT … FOR UPDATE`, and writes the movement that explains it — so the balance and the
 * ledger can never disagree, and two concurrent postings on the same row serialise.
 *
 * Callers lock every row they will touch up front, through lock(), which always locks in
 * ascending (variant_id, location_id) order: two transactions touching the same rows in a
 * different order would otherwise deadlock.
 */
final class Ledger
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Lock (creating when missing) the stock rows for the given pairs.
     *
     * @param  array<array{0: int, 1: int}>  $pairs  [variant_id, location_id]
     * @return array<string, StockLevel> keyed "variantId:locationId"
     */
    public function lock(array $pairs): array
    {
        $this->assertInTransaction();

        usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $levels = [];

        foreach ($pairs as [$variantId, $locationId]) {
            $key = $variantId.':'.$locationId;

            if (isset($levels[$key])) {
                continue;
            }

            $this->db->connection()->statement(
                'INSERT INTO stock_levels (variant_id, location_id, on_hand, reserved, incoming, updated_at)
                 VALUES (?, ?, 0, 0, 0, now()) ON CONFLICT (variant_id, location_id) DO NOTHING',
                [$variantId, $locationId],
            );

            $levels[$key] = StockLevel::query()
                ->where('variant_id', $variantId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $levels;
    }

    public function post(StockLevel $level, VariantReference $variant, MovementType $type, int $quantity, Posting $posting): InventoryMovement
    {
        $this->assertInTransaction();

        if ($quantity === 0) {
            throw InventoryRuleViolation::nonPositiveQuantity();
        }

        if ($type->requiresReason() && trim((string) $posting->reason) === '') {
            throw InventoryRuleViolation::reasonRequired($type->value);
        }

        $before = $level->on_hand;
        $after = $before + $quantity;

        // Overselling is impossible unless this variant explicitly allows backorders.
        if ($after < 0 && ! $variant->allowBackorder) {
            throw InsufficientStock::for($variant->sku, -$quantity, max(0, $before));
        }

        StockLevel::query()->whereKey($level->id)->update(['on_hand' => $after, 'updated_at' => now()]);
        $level->setRawAttributes(['on_hand' => $after] + $level->getAttributes(), true);

        $movement = InventoryMovement::query()->create([
            'variant_id' => $variant->id,
            'location_id' => $level->location_id,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $after,
            'unit_cost_amount' => $posting->unitCost?->amount,
            'unit_cost_currency' => $posting->unitCost?->currency->value,
            'reference_type' => $posting->referenceType,
            'reference_ulid' => $posting->referenceUlid,
            'reason' => $posting->reason,
            'actor_ulid' => $posting->actorUlid,
        ]);

        $this->announce($variant->id, $level, $before - $level->reserved, $after - $level->reserved);

        return $movement;
    }

    /**
     * Change the reserved quantity of a locked row (reservations never move on_hand).
     */
    public function adjustReserved(StockLevel $level, int $delta): void
    {
        $this->assertInTransaction();

        $reserved = $level->reserved + $delta;

        StockLevel::query()->whereKey($level->id)->update(['reserved' => $reserved, 'updated_at' => now()]);
        $level->setRawAttributes(['reserved' => $reserved] + $level->getAttributes(), true);

        $this->announce($level->variant_id, $level, $level->on_hand - ($reserved - $delta), $level->on_hand - $reserved);
    }

    /**
     * Events are dispatched only after the surrounding transaction commits, so nothing is
     * announced for a rolled-back change.
     */
    private function announce(int $variantId, StockLevel $level, int $availableBefore, int $availableAfter): void
    {
        $connection = $this->db->connection();
        $threshold = $level->low_stock_threshold;

        $connection->afterCommit(fn () => StockChanged::dispatch([$variantId]));

        if ($threshold !== null && $availableBefore > $threshold && $availableAfter <= $threshold) {
            $locationUlid = (string) Location::query()->whereKey($level->location_id)->value('ulid');

            $connection->afterCommit(fn () => LowStockReached::dispatch($variantId, $locationUlid, $availableAfter, $threshold));
        }
    }

    private function assertInTransaction(): void
    {
        if ($this->db->connection()->transactionLevel() === 0) {
            throw new LogicException('Ledger postings must run inside a database transaction.');
        }
    }
}
