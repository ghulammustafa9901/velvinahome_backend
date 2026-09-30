<?php

declare(strict_types=1);

namespace Modules\Reporting\Support;

use DateTimeInterface;
use Modules\Orders\Contracts\SalesLedger;
use Modules\Orders\Data\SalesDay;
use Modules\Payments\Contracts\RefundLedger;
use Modules\Settings\Contracts\TenantSettings;

/**
 * What one day's report rows must contain, derived from the order and payment records. Used
 * both to build the rows and to verify them, so the two can never disagree about a rule.
 */
final class ReportFacts
{
    public function __construct(
        private readonly SalesLedger $sales,
        private readonly RefundLedger $refunds,
        private readonly TenantSettings $settings,
    ) {}

    public function timezone(): string
    {
        return $this->settings->string('store.timezone', 'Asia/Karachi');
    }

    public function currency(): string
    {
        return $this->settings->string('store.currency', 'PKR');
    }

    /**
     * The day's rows keyed by column, without ids or rebuild times.
     *
     * @return array{sales: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function day(string $date): array
    {
        $timezone = $this->timezone();
        $orders = [];
        foreach ($this->sales->day($date, $timezone) as $day) {
            $orders[$day->currency] = $day;
        }

        $refunds = $this->refunds->day($date, $timezone);
        $currencies = array_unique([...array_keys($orders), ...array_keys($refunds)]);
        sort($currencies);

        $sales = [];
        $products = [];

        foreach ($currencies as $currency) {
            $day = $orders[$currency] ?? null;

            $sales[] = [
                'date' => $date,
                'currency' => $currency,
                'orders_placed' => $day->ordersPlaced ?? 0,
                'orders_cancelled' => $day->ordersCancelled ?? 0,
                'orders_returned' => $day->ordersReturned ?? 0,
                'units' => $day->units ?? 0,
                'subtotal_amount' => $day->subtotal ?? 0,
                'discount_amount' => $day->discount ?? 0,
                'shipping_amount' => $day->shipping ?? 0,
                'tax_amount' => $day->tax ?? 0,
                'total_amount' => $day->total ?? 0,
                'placed_total_amount' => $day->placedTotal ?? 0,
                'refunded_amount' => $refunds[$currency]['amount'] ?? 0,
                'refunds' => $refunds[$currency]['count'] ?? 0,
                'cod_orders' => $day->codOrders ?? 0,
                'cod_confirmed' => $day->codConfirmed ?? 0,
                'cod_unreachable' => $day->codUnreachable ?? 0,
                'cod_fake' => $day->codFake ?? 0,
                'cod_returned' => $day->codReturned ?? 0,
                'payment_methods' => $day instanceof SalesDay ? $day->paymentMethods : [],
            ];

            foreach ($day instanceof SalesDay ? $day->products : [] as $product) {
                $products[] = [
                    'date' => $date,
                    'currency' => $currency,
                    'product_ulid' => $product['product_ulid'],
                    'title' => mb_substr($product['title'], 0, 255),
                    'units' => $product['units'],
                    'revenue_amount' => $product['revenue'],
                    'orders' => $product['orders'],
                ];
            }
        }

        return ['sales' => $sales, 'products' => $products];
    }

    /**
     * Days whose facts changed after the moment (orders or refunds).
     *
     * @return list<string>
     */
    public function changedSince(DateTimeInterface $since): array
    {
        $timezone = $this->timezone();

        return $this->merge($this->sales->daysChangedSince($since, $timezone), $this->refunds->daysChangedSince($since, $timezone));
    }

    /**
     * @return list<string>
     */
    public function allDays(): array
    {
        $timezone = $this->timezone();

        return $this->merge($this->sales->daysWithOrders($timezone), $this->refunds->daysWithRefunds($timezone));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function merge(array $a, array $b): array
    {
        $days = array_values(array_unique([...$a, ...$b]));
        sort($days);

        return $days;
    }
}
