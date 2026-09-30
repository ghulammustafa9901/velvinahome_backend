<?php

declare(strict_types=1);

namespace Modules\Reporting\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Reporting\Models\ProductDayReport;
use Modules\Reporting\Models\SalesDayReport;
use Modules\Reporting\Support\ReportFacts;
use Modules\Support\Contracts\Action;

/**
 * Replace the report rows of the given days with freshly computed ones. Each day is its own
 * transaction: a day's rows are always all old or all new, never a mix.
 */
final class RebuildReportDays implements Action
{
    public function __construct(
        private readonly ReportFacts $facts,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  list<string>  $dates  Y-m-d, brand-local
     * @return int days rebuilt
     */
    public function handle(array $dates): int
    {
        foreach ($dates as $date) {
            $rows = $this->facts->day($date);
            $now = Carbon::now();

            $this->db->connection()->transaction(function () use ($date, $rows, $now): void {
                SalesDayReport::query()->whereDate('date', $date)->delete();
                ProductDayReport::query()->whereDate('date', $date)->delete();

                foreach ($rows['sales'] as $row) {
                    SalesDayReport::query()->create($row + ['rebuilt_at' => $now]);
                }

                foreach (array_chunk($rows['products'], 500) as $chunk) {
                    ProductDayReport::query()->insert($chunk);
                }
            });
        }

        return count($dates);
    }
}
