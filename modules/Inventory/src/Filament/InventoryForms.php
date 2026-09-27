<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Contracts\VariantLookup;
use Modules\Inventory\Models\Location;

/**
 * Shared form pieces for the inventory workflow screens.
 */
final class InventoryForms
{
    public static function can(string $permission): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can($permission);
    }

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function location(string $name = 'location', string $label = 'Location'): Select
    {
        return Select::make($name)->label($label)->required()
            ->options(fn (): array => Location::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'ulid')->all())
            ->default(fn (): ?string => Location::query()->where('is_default', true)->value('ulid'));
    }

    /**
     * Variant picker searching SKU and product title through the Catalog contract's data.
     */
    public static function variant(string $name = 'variant'): Select
    {
        return Select::make($name)->label('Variant (SKU)')->required()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => app(VariantLookup::class)->search($search))
            ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null
                : (($reference = app(VariantCatalog::class)->findByUlid($value)) === null ? null : $reference->sku.' — '.$reference->title));
    }

    public static function lines(bool $withCost = false, string $quantityLabel = 'Quantity'): Repeater
    {
        return Repeater::make('lines')->required()->minItems(1)->columns($withCost ? 3 : 2)->schema(array_values(array_filter([
            self::variant(),
            TextInput::make('quantity')->label($quantityLabel)->numeric()->integer()->required()->minValue($quantityLabel === 'Counted' ? 0 : 1),
            $withCost ? TextInput::make('unit_cost')->label('Unit cost')->regex('/^\d[\d,]*(\.\d{1,2})?$/')->prefix('Rs') : null,
        ])));
    }
}
