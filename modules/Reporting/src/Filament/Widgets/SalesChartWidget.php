<?php

declare(strict_types=1);

namespace Modules\Reporting\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Modules\Reporting\Filament\ReportAccess;
use Modules\Reporting\Queries\SalesReport;
use Modules\Support\ValueObjects\Money;

final class SalesChartWidget extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Sales by day';

    protected ?string $pollingInterval = null;

    public ?string $filter = '30';

    public static function canView(): bool
    {
        return ReportAccess::allowed();
    }

    protected function getFilters(): array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $report = app(SalesReport::class);
        $days = in_array($this->filter, ['7', '30', '90'], true) ? (int) $this->filter : 30;
        $today = $report->today();
        $rows = $report->days(Carbon::parse($today)->subDays($days - 1)->format('Y-m-d'), $today);
        $perMajor = Money::zero($report->currency())->currency->minorUnitsPerMajor();

        return [
            'datasets' => [
                // Whole major units for the axis; the exact figures are on the sales report.
                ['label' => 'Sales ('.$report->currency().')', 'data' => array_map(fn (array $row): int => intdiv($row['sales'], $perMajor), $rows)],
                ['label' => 'Orders', 'data' => array_map(fn (array $row): int => $row['orders'] - $row['cancelled'], $rows), 'yAxisID' => 'orders'],
            ],
            'labels' => array_map(fn (array $row): string => Carbon::parse($row['date'])->format('M j'), $rows),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true],
                'orders' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
