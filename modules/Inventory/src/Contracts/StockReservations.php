<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Stock commitments for Checkout and Orders (spec §6.5 rule 3, ADR-0010). Every method joins
 * the caller's transaction, so an order and its stock are never out of step.
 */
interface StockReservations
{
    /**
     * @return string reservation ULID
     *
     * @throws \Modules\Inventory\Exceptions\InsufficientStock
     */
    public function reserve(string $variantUlid, int $quantity, string $referenceType, string $referenceUlid, int $ttlMinutes): string;

    /**
     * Convert to a `sale` movement (COD placed, or payment confirmed).
     */
    public function commit(string $reservationUlid, string $orderUlid): void;

    /**
     * Give reserved stock back without a movement (checkout failed, unpaid order cancelled).
     */
    public function release(string $reservationUlid): void;

    /**
     * Put committed stock back with a `return` movement and a reason (cancelled COD order,
     * RTO, customer return) at the location it was sold from.
     */
    public function restock(string $reservationUlid, int $quantity, string $reason, string $orderUlid): void;

    /**
     * @return 'active'|'converted'|'released'|'expired'|null
     */
    public function status(string $reservationUlid): ?string;
}
