<?php

declare(strict_types=1);

namespace Modules\Imports\Filament\Resources\ImportRuns;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\HtmlString;
use Modules\Imports\Models\ImportRun;
use UnitEnum;

final class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Imports';

    protected static ?string $modelLabel = 'import';

    protected static ?int $navigationSort = 20;

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        $actor = self::actor();

        return $actor !== null && ($actor->can('settings.manage') || $actor->can('products.manage') || $actor->can('inventory.adjust'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('source')->formatStateUsing(fn (string $state): string => match ($state) {
                    ImportRun::SOURCE_WOOCOMMERCE => 'WooCommerce',
                    ImportRun::SOURCE_CSV_PRODUCTS => 'Products CSV',
                    ImportRun::SOURCE_CSV_STOCK => 'Stock CSV',
                    default => $state,
                })->description(fn (ImportRun $record): ?string => isset($record->options['store_url']) ? (string) $record->options['store_url'] : null),
                IconColumn::make('dry_run')->label('Dry run')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'gray',
                })->description(fn (ImportRun $record): ?string => $record->step),
                TextColumn::make('counts')->label('Result')->formatStateUsing(fn (ImportRun $record): string => self::summary($record))->wrap(),
                TextColumn::make('issue_count')->label('Issues')->numeric()->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->recordActions([
                Action::make('report')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('gray')
                    ->modalHeading('Import report')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ImportRun $record): HtmlString => self::report($record)),
            ])
            ->poll('5s')
            ->defaultSort('created_at', 'desc');
    }

    public static function summary(ImportRun $run): string
    {
        $parts = [];
        foreach ($run->counts as $entity => $outcomes) {
            $parts[] = $entity.': '.implode(', ', array_map(fn (string $outcome, int $n): string => $n.' '.str_replace('_', ' ', $outcome), array_keys($outcomes), $outcomes));
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * Plain, escaped HTML: issue text quotes the source store's data.
     */
    private static function report(ImportRun $run): HtmlString
    {
        $html = '<div class="space-y-3 text-sm">';

        if ($run->error !== null) {
            $html .= '<p class="text-danger-600 font-medium">'.e($run->error).'</p>';
        }

        $html .= '<p>'.e(self::summary($run)).'</p>';

        if ($run->issues === []) {
            $html .= '<p class="text-gray-500">No issues.</p>';
        } else {
            $html .= '<ul class="space-y-1">';
            foreach ($run->issues as $issue) {
                $html .= '<li><span class="font-medium">'.e(ucfirst($issue['level'])).'</span> · '.e($issue['entity']).($issue['ref'] !== null ? ' '.e($issue['ref']) : '').': '.e($issue['message']).'</li>';
            }
            $html .= '</ul>';

            if ($run->issue_count > count($run->issues)) {
                $html .= '<p class="text-gray-500">…and '.($run->issue_count - count($run->issues)).' more.</p>';
            }
        }

        return new HtmlString($html.'</div>');
    }

    public static function getPages(): array
    {
        return ['index' => ListImportRunsPage::route('/')];
    }
}
