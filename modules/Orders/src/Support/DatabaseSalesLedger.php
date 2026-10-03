<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Modules\Orders\Contracts\SalesLedger;
use Modules\Orders\Data\SalesDay;

/**
 * Sales facts computed with SQL aggregates over `orders` and `order_items`. A day is bounded
 * in UTC from the store's local midnight, so the `placed_at` index is used.
 */
final class DatabaseSalesLedger implements SalesLedger
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function daysChangedSince(DateTimeInterface $since, string $timezone): array
    {
        return $this->days(
            $this->db->connection()->table('orders')->where('updated_at', '>', $since),
            $timezone,
        );
    }

    public function daysWithOrders(string $timezone): array
    {
        return $this->days($this->db->connection()->table('orders'), $timezone);
    }

    public function day(string $date, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($timezone));

        if ($start === null || $start->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a calendar date: {$date}");
        }

        $bounds = [$start->utc(), $start->addDay()->utc()];
        $connection = $this->db->connection();
        $counted = "status <> 'cancelled'";

        $totals = $connection->table('orders')
            ->where('placed_at', '>=', $bounds[0])->where('placed_at', '<', $bounds[1])
            ->groupBy('currency')
            ->selectRaw('currency')
            ->selectRaw('count(*) as placed')
            ->selectRaw("count(*) filter (where status = 'cancelled') as cancelled")
            ->selectRaw("count(*) filter (where status = 'returned') as returned")
            ->selectRaw("coalesce(sum(subtotal_amount) filter (where {$counted}), 0) as subtotal")
            ->selectRaw("coalesce(sum(discount_amount) filter (where {$counted}), 0) as discount")
            ->selectRaw("coalesce(sum(shipping_amount) filter (where {$counted}), 0) as shipping")
            ->selectRaw("coalesce(sum(tax_amount) filter (where {$counted}), 0) as tax")
            ->selectRaw("coalesce(sum(total_amount) filter (where {$counted}), 0) as total")
            ->selectRaw('coalesce(sum(total_amount), 0) as placed_total')
            ->selectRaw("count(*) filter (where payment_method_code = 'cod') as cod")
            ->selectRaw("count(*) filter (where payment_method_code = 'cod' and cod_confirmation = 'confirmed') as cod_confirmed")
            ->selectRaw("count(*) filter (where payment_method_code = 'cod' and cod_confirmation = 'unreachable') as cod_unreachable")
            ->selectRaw("count(*) filter (where payment_method_code = 'cod' and cod_confirmation = 'fake') as cod_fake")
            ->selectRaw("count(*) filter (where payment_method_code = 'cod' and status = 'returned') as cod_returned")
            ->orderBy('currency')
            ->get();

        if ($totals->isEmpty()) {
            return [];
        }

        $items = $connection->table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.placed_at', '>=', $bounds[0])->where('orders.placed_at', '<', $bounds[1])
            ->where('orders.status', '<>', 'cancelled')
            ->groupBy('orders.currency', 'order_items.product_ulid')
            ->selectRaw('orders.currency, order_items.product_ulid')
            ->selectRaw('(array_agg(order_items.title order by orders.placed_at desc, order_items.id desc))[1] as title')
            ->selectRaw('sum(order_items.quantity) as units')
            ->selectRaw('sum(order_items.total_amount) as revenue')
            ->selectRaw('count(distinct orders.id) as orders')
            ->orderBy('order_items.product_ulid')
            ->get()
            ->groupBy('currency');

        $methods = $connection->table('orders')
            ->where('placed_at', '>=', $bounds[0])->where('placed_at', '<', $bounds[1])
            ->where('status', '<>', 'cancelled')
            ->groupBy('currency', 'payment_method_code')
            ->selectRaw('currency, payment_method_code, count(*) as orders, sum(total_amount) as total')
            ->orderBy('payment_method_code')
            ->get()
            ->groupBy('currency');

        $days = [];

        foreach ($totals as $row) {
            $currency = (string) $row->currency;
            $products = [];
            $units = 0;

            foreach ($items->get($currency, collect()) as $item) {
                $units += (int) $item->units;
                $products[] = [
                    'product_ulid' => (string) $item->product_ulid,
                    'title' => (string) $item->title,
                    'units' => (int) $item->units,
                    'revenue' => (int) $item->revenue,
                    'orders' => (int) $item->orders,
                ];
            }

            $paymentMethods = [];
            foreach ($methods->get($currency, collect()) as $method) {
                $paymentMethods[(string) $method->payment_method_code] = ['orders' => (int) $method->orders, 'total' => (int) $method->total];
            }

            $days[] = new SalesDay(
                date: $date,
                currency: $currency,
                ordersPlaced: (int) $row->placed,
                ordersCancelled: (int) $row->cancelled,
                ordersReturned: (int) $row->returned,
                units: $units,
                subtotal: (int) $row->subtotal,
                discount: (int) $row->discount,
                shipping: (int) $row->shipping,
                tax: (int) $row->tax,
                total: (int) $row->total,
                placedTotal: (int) $row->placed_total,
                codOrders: (int) $row->cod,
                codConfirmed: (int) $row->cod_confirmed,
                codUnreachable: (int) $row->cod_unreachable,
                codFake: (int) $row->cod_fake,
                codReturned: (int) $row->cod_returned,
                paymentMethods: $paymentMethods,
                products: $products,
            );
        }

        return $days;
    }

    /**
     * @return list<string>
     */
    private function days(Builder $orders, string $timezone): array
    {
        new DateTimeZone($timezone); // reject an unknown zone before it reaches SQL

        return array_values($orders
            ->selectRaw('distinct (placed_at at time zone ?)::date as day', [$timezone])
            ->orderBy('day')
            ->pluck('day')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))
            ->all());
    }
}
