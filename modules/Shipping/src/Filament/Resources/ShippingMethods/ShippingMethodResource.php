<?php

declare(strict_types=1);

namespace Modules\Shipping\Filament\Resources\ShippingMethods;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Shipping\Actions\SaveShippingMethod;
use Modules\Shipping\Actions\SaveShippingZone;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRate;
use Modules\Shipping\Models\ShippingZone;
use Modules\Support\ValueObjects\Money;
use UnitEnum;

/**
 * Delivery methods and the zones they serve, on one screen: most stores have one or two
 * zones and a handful of methods.
 */
final class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Shipping';

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::actor()?->can('settings.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function currency(): string
    {
        return app(StoreSettings::class)->string('store.currency', 'PKR');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['zone', 'rates']))
            ->columns([
                TextColumn::make('zone.name')->label('Zone')->sortable(),
                TextColumn::make('name')->description(fn (ShippingMethod $record): string => $record->code),
                TextColumn::make('rate')->label('Rate')->state(fn (ShippingMethod $record): string => self::rateSummary($record)),
                TextColumn::make('min_days')->label('Delivery')->formatStateUsing(fn (ShippingMethod $record): string => ($record->min_days ?? '?').'–'.($record->max_days ?? '?').' days'),
                IconColumn::make('is_cod_allowed')->boolean()->label('COD'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (ShippingMethod $record): array => self::toForm($record))
                    ->schema(self::fields())
                    ->action(fn (ShippingMethod $record, array $data) => self::save($record, $data)),
                Action::make('zone')->label('Edit zone')->icon(Heroicon::OutlinedGlobeAsiaAustralia)
                    ->fillForm(fn (ShippingMethod $record): array => [
                        'name' => $record->zone->name,
                        'countries' => $record->zone->countries,
                        'provinces' => $record->zone->provinces,
                        'cities' => $record->zone->cities,
                        'is_active' => $record->zone->is_active,
                    ])
                    ->schema(self::zoneFields())
                    ->action(fn (ShippingMethod $record, array $data) => self::saveZone($record->zone, $data)),
            ]);
    }

    public static function rateSummary(ShippingMethod $method): string
    {
        /** @var ShippingRate|null $rate */
        $rate = $method->rates->first();

        if ($rate === null) {
            return '—';
        }

        return $rate->amount->format().($rate->free_over_amount === null ? '' : ', free over '.Money::of($rate->free_over_amount, $rate->amount->currency)->format());
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            Select::make('zone')->required()->options(fn (): array => ShippingZone::query()->orderBy('name')->pluck('name', 'ulid')->all()),
            TextInput::make('code')->required()->regex('/^[a-z0-9_]{2,50}$/')->helperText('Used by the storefront, e.g. standard, express.'),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('rate')->label('Rate (Rs)')->required()->regex('/^\d[\d,]*(\.\d{1,2})?$/'),
            TextInput::make('free_over')->label('Free over (Rs)')->regex('/^\d[\d,]*(\.\d{1,2})?$/'),
            TextInput::make('min_days')->numeric()->integer()->minValue(0),
            TextInput::make('max_days')->numeric()->integer()->minValue(0),
            Toggle::make('is_cod_allowed')->label('Cash on Delivery allowed')->default(true),
            Toggle::make('is_active')->default(true),
        ];
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function zoneFields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(120),
            TagsInput::make('countries')->placeholder('PK')->helperText('Two-letter codes. Empty means any country.'),
            TagsInput::make('provinces')->helperText('Empty means every province.'),
            TagsInput::make('cities')->helperText('Empty means every city. A city list makes this zone win over broader ones.'),
            Toggle::make('is_active')->default(true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toForm(ShippingMethod $method): array
    {
        /** @var ShippingRate|null $rate */
        $rate = $method->rates()->first();

        return [
            'zone' => $method->zone->ulid,
            'code' => $method->code,
            'name' => $method->name,
            'rate' => $rate?->amount->toDecimalString(),
            'free_over' => $rate?->free_over_amount === null ? null : Money::of($rate->free_over_amount, $rate->amount->currency)->toDecimalString(),
            'min_days' => $method->min_days,
            'max_days' => $method->max_days,
            'is_cod_allowed' => $method->is_cod_allowed,
            'is_active' => $method->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?ShippingMethod $method, array $data): ShippingMethod
    {
        $money = fn (mixed $value): ?Money => blank($value) ? null : Money::parse(str_replace(',', '', (string) $value), self::currency());

        return app(SaveShippingMethod::class)->handle(
            ShippingZone::query()->where('ulid', (string) $data['zone'])->firstOrFail(),
            $method,
            (string) $data['code'],
            (string) $data['name'],
            $money($data['rate']) ?? Money::zero(self::currency()),
            $money($data['free_over'] ?? null),
            (bool) ($data['is_cod_allowed'] ?? true),
            (bool) ($data['is_active'] ?? true),
            blank($data['min_days'] ?? null) ? null : (int) $data['min_days'],
            blank($data['max_days'] ?? null) ? null : (int) $data['max_days'],
            self::actor(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveZone(?ShippingZone $zone, array $data): ShippingZone
    {
        return app(SaveShippingZone::class)->handle(
            $zone,
            (string) $data['name'],
            array_values((array) ($data['countries'] ?? [])),
            array_values((array) ($data['provinces'] ?? [])),
            array_values((array) ($data['cities'] ?? [])),
            (bool) ($data['is_active'] ?? true),
            self::actor(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ListShippingMethodsPage::route('/')];
    }
}
