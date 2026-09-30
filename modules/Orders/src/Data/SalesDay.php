<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

/**
 * One calendar day of orders in one currency, straight from the order records (for
 * reporting). "Counted" orders are every order placed that day except cancelled ones; money
 * is in minor units.
 */
final readonly class SalesDay
{
    /**
     * @param  array<string, array{orders: int, total: int}>  $paymentMethods  counted orders per method
     * @param  list<array{product_ulid: string, title: string, units: int, revenue: int, orders: int}>  $products  counted items per product
     */
    public function __construct(
        public string $date,
        public string $currency,
        public int $ordersPlaced,
        public int $ordersCancelled,
        public int $ordersReturned,
        public int $units,
        public int $subtotal,
        public int $discount,
        public int $shipping,
        public int $tax,
        public int $total,
        public int $placedTotal,
        public int $codOrders,
        public int $codConfirmed,
        public int $codUnreachable,
        public int $codFake,
        public int $codReturned,
        public array $paymentMethods,
        public array $products,
    ) {}
}
