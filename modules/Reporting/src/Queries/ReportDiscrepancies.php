<?php

declare(strict_types=1);

namespace Modules\Reporting\Queries;

use Illuminate\Support\Carbon;
use Modules\Reporting\Models\ProductDayReport;
use Modules\Reporting\Models\SalesDayReport;
use Modules\Reporting\Support\ReportFacts;

/**
 * Compares the stored report rows with what the order and payment records say today — the
 * "reports match raw data" check. Read-only: it reports differences, it fixes nothing.
 */
final class ReportDiscrepancies
{
    public function __construct(private readonly ReportFacts $facts) {}

    /**
     * @return list<string> one line per difference; empty when the reports are exact
     */
    public function between(string $from, string $to): array
    {
        $problems = [];

        for ($day = Carbon::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $expected = $this->facts->day($date);

            $stored = SalesDayReport::query()->whereDate('date', $date)->orderBy('currency')->get()
                ->map(fn (SalesDayReport $row): array => ['date' => $date, 'currency' => $row->currency]
                    + $row->only(SalesDayReport::COUNTERS) + ['payment_methods' => $row->payment_methods])
                ->all();

            $problems = [...$problems, ...$this->compare($date, 'sales', $this->keyed($expected['sales'], ['currency']), $this->keyed($stored, ['currency']))];

            $storedProducts = ProductDayReport::query()->whereDate('date', $date)->get()
                ->map(fn (ProductDayReport $row): array => ['date' => $date] + $row->only(['currency', 'product_ulid', 'title', 'units', 'revenue_amount', 'orders']))
                ->all();

            $problems = [...$problems, ...$this->compare($date, 'product', $this->keyed($expected['products'], ['currency', 'product_ulid']), $this->keyed($storedProducts, ['currency', 'product_ulid']))];
        }

        return $problems;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $rows, array $keys): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[implode('/', array_map(fn (string $key): string => (string) $row[$key], $keys))] = $row;
        }

        return $keyed;
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $stored
     * @return list<string>
     */
    private function compare(string $date, string $kind, array $expected, array $stored): array
    {
        $problems = [];

        foreach (array_unique([...array_keys($expected), ...array_keys($stored)]) as $key) {
            if (! isset($stored[$key])) {
                $problems[] = "{$date} {$kind} {$key}: missing from the report";

                continue;
            }

            if (! isset($expected[$key])) {
                $problems[] = "{$date} {$kind} {$key}: in the report but not in the records";

                continue;
            }

            foreach ($expected[$key] as $column => $value) {
                if ($this->normalise($stored[$key][$column] ?? null) !== $this->normalise($value)) {
                    $problems[] = sprintf('%s %s %s: %s is %s in the report, %s in the records', $date, $kind, $key, $column,
                        json_encode($stored[$key][$column] ?? null), json_encode($value));
                }
            }
        }

        return $problems;
    }

    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(fn (mixed $item): mixed => $this->normalise($item), $value);
        }

        return is_numeric($value) ? (int) $value : $value;
    }
}
