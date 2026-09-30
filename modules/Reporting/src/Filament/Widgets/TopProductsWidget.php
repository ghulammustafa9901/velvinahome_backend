<?php

declare(strict_types=1);

namespace Modules\Reporting\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Modules\Reporting\Filament\ReportAccess;
use Modules\Reporting\Queries\SalesReport;

final class TopProductsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ReportAccess::allowed();
    }

    public function table(Table $table): Table
    {
        $report = app(SalesReport::class);
        $currency = $report->currency();

        return $table
            ->heading('Best sellers, last 30 days')
            ->records(function () use ($report): array {
                $today = $report->today();

                return collect($report->topProducts(Carbon::parse($today)->subDays(29)->format('Y-m-d'), $today))
                    ->mapWithKeys(fn (array $row): array => [$row['product_ulid'] => $row])
                    ->all();
            })
            ->columns([
                TextColumn::make('title')->label('Product'),
                TextColumn::make('units')->numeric(),
                TextColumn::make('orders')->numeric(),
                TextColumn::make('revenue')->formatStateUsing(fn (int $state): string => ReportAccess::money($state, $currency)),
            ])
            ->paginated(false);
    }
}
