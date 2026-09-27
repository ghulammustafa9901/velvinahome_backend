<?php

declare(strict_types=1);

namespace Modules\Tax\Filament\Resources\TaxRates;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Tax\Actions\SaveTaxRate;
use Modules\Tax\Models\TaxRate;
use UnitEnum;

/**
 * Tax rates. Whether prices include tax is the store setting `tax.mode`.
 */
final class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('rate_bps')->label('Rate')->formatStateUsing(fn (int $state): string => self::percent($state).'%'),
                TextColumn::make('country_code')->label('Country')->placeholder('All'),
                TextColumn::make('province')->placeholder('All'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (TaxRate $record): array => [
                        'name' => $record->name,
                        'rate' => self::percent($record->rate_bps),
                        'country_code' => $record->country_code,
                        'province' => $record->province,
                        'is_active' => $record->is_active,
                    ])
                    ->schema(self::fields())
                    ->action(fn (TaxRate $record, array $data) => self::save($record, $data)),
            ]);
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(100)->placeholder('GST'),
            TextInput::make('rate')->label('Rate (%)')->required()->regex('/^\d{1,3}(\.\d{1,2})?$/'),
            TextInput::make('country_code')->label('Country code')->length(2)->placeholder('PK')->helperText('Leave empty for a rate that applies everywhere.'),
            TextInput::make('province')->maxLength(100)->helperText('Only for a province-specific rate.'),
            Toggle::make('is_active')->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?TaxRate $rate, array $data): TaxRate
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $data['rate'], 2), 2, '');
        $bps = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return app(SaveTaxRate::class)->handle(
            $rate,
            (string) $data['name'],
            $bps,
            blank($data['country_code'] ?? null) ? null : (string) $data['country_code'],
            blank($data['province'] ?? null) ? null : (string) $data['province'],
            (bool) ($data['is_active'] ?? true),
            self::actor(),
        );
    }

    private static function percent(int $bps): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($bps, 100), $bps % 100), '0'), '.');
    }

    public static function getPages(): array
    {
        return ['index' => ListTaxRatesPage::route('/')];
    }
}
