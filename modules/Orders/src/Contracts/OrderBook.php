<?php

declare(strict_types=1);

namespace Modules\Orders\Contracts;

use Modules\Orders\Data\NewOrder;

/**
 * What Checkout and Notifications may ask of Orders.
 */
interface OrderBook
{
    /**
     * Record a placed order, its snapshots, addresses and first timeline entry, and queue
     * `order.placed`. Joins the caller's transaction.
     *
     * @return string the order number
     */
    public function place(NewOrder $order): string;

    /**
     * @return array{ulid: string, number: string, request_hash: string}|null
     */
    public function findByIdempotencyKey(string $key): ?array;

    /**
     * Plain data describing an order, for emails and other messages.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $orderUlid): ?array;

    /**
     * The customer's most recent delivered (or completed) order containing this product, for
     * "verified purchase" checks.
     *
     * @return string|null order ULID
     */
    public function deliveredOrderWithProduct(int $customerId, string $productUlid): ?string;
}
