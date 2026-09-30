<?php

declare(strict_types=1);

namespace Modules\Reporting\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Modules\Reporting\Filament\ReportAccess;
use Modules\Reporting\Queries\SalesReport;

/**
 * Dashboard headline figures: today and the last 30 days, from the report aggregates.
 */
final class SalesOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return ReportAccess::allowed();
    }

    protected function getStats(): array
    {
        $report = app(SalesReport::class);
        $currency = $report->currency();
        $today = $report->today();
        $from = Carbon::parse($today)->subDays(29)->format('Y-m-d');

        $day = $report->totals($today, $today);
        $month = $report->totals($from, $today);
        $trend = array_map(fn (array $row): int => $row['sales'], $report->days($from, $today));
        $decided = $month['cod_confirmed'] + $month['cod_rejected'];

        return [
            Stat::make('Sales today', ReportAccess::money($day['sales'], $currency))
                ->description(($day['orders'] - $day['cancelled']).' orders'),
            Stat::make('Sales, last 30 days', ReportAccess::money($month['net'], $currency))
                ->description('After '.ReportAccess::money($month['refunded'], $currency).' refunds')
                // Chart.js plots integers as well; Filament's array<float> is a docblock type only.
                // @phpstan-ignore argument.type
                ->chart($trend),
            Stat::make('Average order', ReportAccess::money($month['average_order'], $currency))
                ->description(($month['orders'] - $month['cancelled']).' orders in 30 days'),
            Stat::make('COD confirmed', $decided > 0 ? intdiv(100 * $month['cod_confirmed'], $decided).'%' : '—')
                ->description($month['cod_returned'].' returned to origin in 30 days'),
        ];
    }
}
