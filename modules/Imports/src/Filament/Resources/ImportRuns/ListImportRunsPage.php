<?php

declare(strict_types=1);

namespace Modules\Imports\Filament\Resources\ImportRuns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Imports\Actions\StartCsvImport;
use Modules\Imports\Actions\StartWooCommerceImport;
use Modules\Imports\Models\ImportRun;

final class ListImportRunsPage extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('woocommerce')->label('Import from WooCommerce')->icon(Heroicon::OutlinedCloudArrowDown)
                ->visible(fn (): bool => ImportRunResource::actor()?->can('settings.manage') ?? false)
                ->modalDescription('Create read-only REST API keys in WooCommerce → Settings → Advanced → REST API. Run a dry run first: it checks everything and writes nothing.')
                ->schema([
                    TextInput::make('store_url')->label('Store address')->placeholder('https://shop.example.com')->required()->maxLength(255),
                    TextInput::make('consumer_key')->required()->maxLength(80)->autocomplete(false),
                    TextInput::make('consumer_secret')->password()->required()->maxLength(80)->autocomplete(false),
                    CheckboxList::make('entities')->label('Import')->required()->default(['categories', 'products', 'customers', 'orders'])
                        ->options(['categories' => 'Categories', 'products' => 'Products, stock, images and old URLs', 'customers' => 'Customers and addresses', 'orders' => 'Order history (read-only)']),
                    Toggle::make('dry_run')->label('Dry run (check only, write nothing)')->default(true),
                ])
                ->action(function (array $data): void {
                    app(StartWooCommerceImport::class)->handle(
                        (string) $data['store_url'], (string) $data['consumer_key'], (string) $data['consumer_secret'],
                        (bool) $data['dry_run'], array_values((array) $data['entities']), ImportRunResource::actor(),
                    );
                    Notification::make()->title('Import queued')->body('Progress appears in the list below.')->success()->send();
                }),
            ActionGroup::make([
                $this->csv(ImportRun::SOURCE_CSV_PRODUCTS, 'Products CSV', 'products.manage', 'Columns: handle, title, sku, price; optional description, category (slug), status, option1_name/option1_value … option3, compare_at_price, weight_grams, barcode, stock. One row per variant.'),
                $this->csv(ImportRun::SOURCE_CSV_STOCK, 'Stock CSV', 'inventory.adjust', 'Columns: sku, quantity. Each quantity is counted as on hand at the default location.'),
            ])->label('Import CSV')->icon(Heroicon::OutlinedTableCells)->button()->color('gray'),
        ];
    }

    private function csv(string $source, string $label, string $permission, string $help): Action
    {
        return Action::make($source)->label($label)
            ->visible(fn (): bool => ImportRunResource::actor()?->can($permission) ?? false)
            ->modalDescription($help)
            ->schema([
                FileUpload::make('file')->disk(StartCsvImport::DISK)->directory('imports')->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(StartCsvImport::MAX_BYTES / 1024)->required(),
                Toggle::make('dry_run')->label('Dry run (check only, write nothing)')->default(true),
            ])
            ->action(function (array $data) use ($source): void {
                app(StartCsvImport::class)->handle($source, (string) $data['file'], (bool) $data['dry_run'], ImportRunResource::actor());
                Notification::make()->title('Import queued')->success()->send();
            });
    }
}
