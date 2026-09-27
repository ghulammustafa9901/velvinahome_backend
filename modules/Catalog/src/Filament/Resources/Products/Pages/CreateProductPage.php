<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Actions\CreateProduct;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Filament\Resources\Products\ProductResource;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;

/**
 * New product with its option matrix and variants, created as a draft by CreateProduct.
 * Prices are typed in major units ("2499.50") and converted exactly, never through a float.
 */
final class CreateProductPage extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product')->schema(ProductResource::detailFields()),
            Section::make('Options')
                ->description('Leave empty for a simple product with one variant.')
                ->schema([
                    Repeater::make('options')->schema([
                        TextInput::make('name')->required()->placeholder('Color'),
                        TagsInput::make('values')->required()->placeholder('Red, Blue'),
                    ])->columns(2)->defaultItems(0),
                ]),
            Section::make('Variants')->schema([
                Repeater::make('variants')->schema([
                    TextInput::make('sku')->required()->regex('/^[A-Za-z0-9._\/-]{1,64}$/'),
                    TextInput::make('price')->required()->regex('/^\d[\d,]*(\.\d{1,2})?$/')->prefix('Rs'),
                    TextInput::make('compare_at_price')->regex('/^\d[\d,]*(\.\d{1,2})?$/')->prefix('Rs'),
                    TextInput::make('barcode')->maxLength(64),
                    TextInput::make('weight_grams')->numeric()->minValue(0),
                    Toggle::make('allow_backorder'),
                    KeyValue::make('option_values')->keyLabel('Option')->valueLabel('Value')
                        ->helperText('One entry per option, e.g. Color → Red.'),
                ])->columns(3)->minItems(1)->defaultItems(1),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $currency = app(TenantSettings::class)->string('store.currency', 'PKR');
        $toMinor = fn (?string $value): ?int => $value === null || $value === '' ? null : Money::parse($value, $currency)->amount;

        // Empty lists are omitted rather than sent as [], which the DTO's validation would
        // read as "present but missing".
        return app(CreateProduct::class)->handle(ProductData::validateAndCreate(array_filter([
            'title' => $data['title'] ?? null,
            'brandUlid' => $data['brand'] ?? null,
            'categoryUlids' => array_values($data['categories'] ?? []),
            'shortDescription' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'tags' => array_values($data['tags'] ?? []),
            'options' => array_values(array_map(fn (array $option): array => [
                'name' => $option['name'],
                'values' => array_values($option['values'] ?? []),
            ], $data['options'] ?? [])),
            'variants' => array_values(array_map(fn (array $variant): array => [
                'sku' => $variant['sku'],
                'priceAmount' => $toMinor($variant['price'] ?? null),
                'compareAtPriceAmount' => $toMinor($variant['compare_at_price'] ?? null),
                'barcode' => $variant['barcode'] ?? null,
                'weightGrams' => isset($variant['weight_grams']) && $variant['weight_grams'] !== '' ? (int) $variant['weight_grams'] : null,
                'allowBackorder' => (bool) ($variant['allow_backorder'] ?? false),
                'optionValues' => $variant['option_values'] ?? [],
            ], $data['variants'] ?? [])),
        ], fn (mixed $value): bool => $value !== [] && $value !== null)), StaffAccess::actor());
    }
}
