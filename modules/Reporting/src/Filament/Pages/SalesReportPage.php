<?php

declare(strict_types=1);

namespace Modules\Reporting\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Modules\Reporting\Filament\ReportAccess;
use Modules\Reporting\Queries\SalesReport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Sales by day for a date range, with totals and a CSV export. Reads the report aggregates
 * only; figures are refreshed every few minutes by the scheduler.
 */
final class SalesReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Sales report';

    protected static ?string $title = 'Sales report';

    protected static ?string $slug = 'reports/sales';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return ReportAccess::allowed();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $report = app(SalesReport::class);
        $currency = $report->currency();
        $money = fn (int $state): string => ReportAccess::money($state, $currency);

        return $table
            ->records(function (?array $filters) use ($report): array {
                [$from, $to] = $this->range($filters);

                return collect(array_reverse($report->days($from, $to)))
                    ->mapWithKeys(fn (array $day): array => [$day['date'] => $day])
                    ->all();
            })
            ->heading(function () use ($report, $money): string {
                [$from, $to] = $this->range($this->tableFilters);
                $totals = $report->totals($from, $to);

                return sprintf(
                    '%s – %s · %d orders · sales %s · refunds %s · net %s · average order %s',
                    $from, $to, $totals['orders'] - $totals['cancelled'], $money($totals['sales']),
                    $money($totals['refunded']), $money($totals['net']), $money($totals['average_order']),
                );
            })
            ->columns([
                TextColumn::make('date')->date(),
                TextColumn::make('orders')->numeric(),
                TextColumn::make('cancelled')->numeric(),
                TextColumn::make('units')->numeric(),
                TextColumn::make('sales')->label('Sales')->formatStateUsing($money),
                TextColumn::make('discount')->formatStateUsing($money),
                TextColumn::make('shipping')->formatStateUsing($money),
                TextColumn::make('tax')->formatStateUsing($money),
                TextColumn::make('refunded')->formatStateUsing($money),
            ])
            ->filters([
                Filter::make('range')
                    ->schema([
                        DatePicker::make('from')->default(fn (): string => Carbon::parse($report->today())->subDays(29)->format('Y-m-d')),
                        DatePicker::make('to')->default(fn (): string => $report->today()),
                    ]),
            ])
            ->paginated([31, 92, 'all'])
            ->defaultPaginationPageOption(31);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => $this->csv()),
        ];
    }

    private function csv(): StreamedResponse
    {
        [$from, $to] = $this->range($this->tableFilters);
        $days = app(SalesReport::class)->days($from, $to);

        return response()->streamDownload(function () use ($days): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // Minor units, so a spreadsheet never rounds money.
            fputcsv($out, ['date', 'orders', 'cancelled', 'units', 'sales_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'refunded_minor'], escape: '');
            foreach ($days as $day) {
                fputcsv($out, array_values($day), escape: '');
            }

            fclose($out);
        }, "sales-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: string, 1: string}
     */
    private function range(?array $filters): array
    {
        $report = app(SalesReport::class);
        $to = $this->date($filters['range']['to'] ?? null) ?? $report->today();
        $from = $this->date($filters['range']['from'] ?? null) ?? Carbon::parse($to)->subDays(29)->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // At most about a year per view keeps the page and the export fast.
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            $from = Carbon::parse($to)->subDays(366)->format('Y-m-d');
        }

        return [$from, $to];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }
}
