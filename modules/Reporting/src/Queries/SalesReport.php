<?php

declare(strict_types=1);

namespace Modules\Reporting\Queries;

use Illuminate\Support\Carbon;
use Modules\Reporting\Models\ProductDayReport;
use Modules\Reporting\Models\SalesDayReport;
use Modules\Reporting\Support\ReportFacts;

/**
 * Reads the brand's own aggregates only (never orders), in the brand's currency. Days are
 * inclusive, brand-local.
 */
final class SalesReport
{
    public function __construct(private readonly ReportFacts $facts) {}

    public function currency(): string
    {
        return $this->facts->currency();
    }

    public function today(): string
    {
        return Carbon::now($this->facts->timezone())->format('Y-m-d');
    }

    /**
     * @return array{orders: int, cancelled: int, units: int, sales: int, discount: int, shipping: int, tax: int, refunded: int, net: int, average_order: int, cod_orders: int, cod_confirmed: int, cod_rejected: int, cod_returned: int}
     */
    public function totals(string $from, string $to): array
    {
        $row = SalesDayReport::query()
            ->where('currency', $this->currency())
            ->whereBetween('date', [$from, $to])
            ->selectRaw('coalesce(sum(orders_placed), 0) as orders, coalesce(sum(orders_cancelled), 0) as cancelled, coalesce(sum(units), 0) as units')
            ->selectRaw('coalesce(sum(total_amount), 0) as sales, coalesce(sum(discount_amount), 0) as discount, coalesce(sum(shipping_amount), 0) as shipping')
            ->selectRaw('coalesce(sum(tax_amount), 0) as tax, coalesce(sum(refunded_amount), 0) as refunded')
            ->selectRaw('coalesce(sum(cod_orders), 0) as cod_orders, coalesce(sum(cod_confirmed), 0) as cod_confirmed')
            ->selectRaw('coalesce(sum(cod_unreachable + cod_fake), 0) as cod_rejected, coalesce(sum(cod_returned), 0) as cod_returned')
            ->toBase()
            ->first();

        $counted = (int) ($row->orders ?? 0) - (int) ($row->cancelled ?? 0);
        $sales = (int) ($row->sales ?? 0);

        return [
            'orders' => (int) ($row->orders ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'units' => (int) ($row->units ?? 0),
            'sales' => $sales,
            'discount' => (int) ($row->discount ?? 0),
            'shipping' => (int) ($row->shipping ?? 0),
            'tax' => (int) ($row->tax ?? 0),
            'refunded' => (int) ($row->refunded ?? 0),
            'net' => $sales - (int) ($row->refunded ?? 0),
            'average_order' => $counted > 0 ? intdiv($sales, $counted) : 0,
            'cod_orders' => (int) ($row->cod_orders ?? 0),
            'cod_confirmed' => (int) ($row->cod_confirmed ?? 0),
            'cod_rejected' => (int) ($row->cod_rejected ?? 0),
            'cod_returned' => (int) ($row->cod_returned ?? 0),
        ];
    }

    /**
     * Every day in the range, including days without sales (zeros), oldest first.
     *
     * @return list<array{date: string, orders: int, cancelled: int, units: int, sales: int, discount: int, shipping: int, tax: int, refunded: int}>
     */
    public function days(string $from, string $to): array
    {
        $rows = SalesDayReport::query()
            ->where('currency', $this->currency())
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn (SalesDayReport $row): string => $row->date->format('Y-m-d'));

        $days = [];
        for ($day = Carbon::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
            $row = $rows->get($day->format('Y-m-d'));
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'orders' => $row->orders_placed ?? 0,
                'cancelled' => $row->orders_cancelled ?? 0,
                'units' => $row->units ?? 0,
                'sales' => $row->total_amount ?? 0,
                'discount' => $row->discount_amount ?? 0,
                'shipping' => $row->shipping_amount ?? 0,
                'tax' => $row->tax_amount ?? 0,
                'refunded' => $row->refunded_amount ?? 0,
            ];
        }

        return $days;
    }

    /**
     * @return list<array{product_ulid: string, title: string, units: int, revenue: int, orders: int}>
     */
    public function topProducts(string $from, string $to, int $limit = 10): array
    {
        return array_values(ProductDayReport::query()
            ->where('currency', $this->currency())
            ->whereBetween('date', [$from, $to])
            ->groupBy('product_ulid')
            ->selectRaw('product_ulid, (array_agg(title order by date desc))[1] as title')
            ->selectRaw('sum(units) as units, sum(revenue_amount) as revenue, sum(orders) as orders')
            ->orderByDesc('revenue')
            ->orderBy('product_ulid')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'product_ulid' => (string) $row->product_ulid,
                'title' => (string) $row->title,
                'units' => (int) $row->units,
                'revenue' => (int) $row->revenue,
                'orders' => (int) $row->orders,
            ])
            ->all());
    }
}
