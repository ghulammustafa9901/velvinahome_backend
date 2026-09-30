<?php

declare(strict_types=1);

namespace Modules\Pricing\Filament\Resources\Promotions;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Contracts\CategoryDirectory;
use Modules\Pricing\Actions\SavePromotion;
use Modules\Pricing\Models\Promotion;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;
use UnitEnum;

/**
 * Automatic promotions. Percentages are typed as percent (stored as basis points), amounts in
 * rupees (stored in paisa) — parsed as decimal strings, never floats.
 */
final class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    private const DECIMAL = '/^\d[\d,]*(\.\d{1,2})?$/';

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::actor()?->can('promotions.manage') ?? false;
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
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? $state),
                TextColumn::make('config')->label('Offer')->formatStateUsing(fn (Promotion $record): string => self::describe($record))->wrap(),
                TextColumn::make('priority')->numeric()->sortable(),
                TextColumn::make('ends_at')->dateTime()->placeholder('No end'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (Promotion $record): array => self::toForm($record))
                    ->schema(self::fields())
                    ->action(fn (Promotion $record, array $data) => self::save($record, $data)),
            ])
            ->defaultSort('priority');
    }

    public const TYPES = [
        'percentage_sale' => 'Sale (percent off)',
        'spend_tier' => 'Spend more, save more',
        'buy_x_get_y' => 'Buy X, get Y',
    ];

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        $type = fn (string ...$types) => fn (Get $get): bool => in_array($get('type'), $types, true);

        return [
            TextInput::make('name')->required()->maxLength(120)->helperText('Shown to customers in the cart, e.g. "Eid sale 20% off".'),
            Select::make('type')->required()->live()->options(self::TYPES),
            TextInput::make('percent')->label(fn (Get $get): string => $get('type') === 'buy_x_get_y' ? 'Discount on the free items (%)' : 'Percent off')
                ->visible($type('percentage_sale', 'buy_x_get_y'))->required($type('percentage_sale', 'buy_x_get_y'))
                ->default('100')->regex(self::DECIMAL),
            TextInput::make('buy')->label('Buy (items)')->numeric()->integer()->minValue(1)->maxValue(100)
                ->visible($type('buy_x_get_y'))->required($type('buy_x_get_y')),
            TextInput::make('get')->label('Get (items)')->numeric()->integer()->minValue(1)->maxValue(100)
                ->visible($type('buy_x_get_y'))->required($type('buy_x_get_y')),
            Repeater::make('tiers')->visible($type('spend_tier'))->required($type('spend_tier'))->minItems(1)->maxItems(10)
                ->schema([
                    TextInput::make('min_spend')->label('When the items cost at least (Rs)')->required()->regex(self::DECIMAL),
                    Select::make('kind')->label('Discount')->required()->options(['percentage' => 'Percent off', 'fixed' => 'Amount off (Rs)']),
                    TextInput::make('value')->required()->regex(self::DECIMAL),
                ])->columns(3),
            Select::make('category_ulids')->label('Only these categories')->multiple()->searchable()
                ->options(fn (): array => app(CategoryDirectory::class)->options())
                ->helperText('Leave empty for every product. Subcategories are included.'),
            TextInput::make('priority')->numeric()->integer()->default(0)->helperText('Lower runs first. An item gets at most one automatic promotion.'),
            DateTimePicker::make('starts_at'),
            DateTimePicker::make('ends_at')->after('starts_at'),
            Toggle::make('is_active')->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Promotion $promotion, array $data): Promotion
    {
        $type = (string) $data['type'];

        $config = match ($type) {
            'percentage_sale' => ['percent_bps' => self::hundredths((string) $data['percent'])],
            'buy_x_get_y' => ['buy' => (int) $data['buy'], 'get' => (int) $data['get'], 'percent_bps' => self::hundredths((string) $data['percent'])],
            default => ['tiers' => array_values(array_map(fn (array $tier): array => [
                'min_spend' => self::hundredths((string) $tier['min_spend']),
                'type' => (string) $tier['kind'],
                'value' => self::hundredths((string) $tier['value']),
            ], (array) ($data['tiers'] ?? [])))],
        };

        return app(SavePromotion::class)->handle(
            $promotion,
            (string) $data['name'],
            $type,
            $config,
            (bool) ($data['is_active'] ?? true),
            (int) ($data['priority'] ?? 0),
            blank($data['starts_at'] ?? null) ? null : CarbonImmutable::parse((string) $data['starts_at']),
            blank($data['ends_at'] ?? null) ? null : CarbonImmutable::parse((string) $data['ends_at']),
            array_values(array_map('strval', (array) ($data['category_ulids'] ?? []))),
            $promotion === null ? [] : ($promotion->conditions['product_ulids'] ?? []),
            self::actor(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toForm(Promotion $promotion): array
    {
        return [
            'name' => $promotion->name,
            'type' => $promotion->type,
            'percent' => isset($promotion->config['percent_bps']) ? self::decimal((int) $promotion->config['percent_bps']) : null,
            'buy' => $promotion->config['buy'] ?? null,
            'get' => $promotion->config['get'] ?? null,
            'tiers' => array_map(fn (array $tier): array => [
                'min_spend' => self::decimal((int) $tier['min_spend']),
                'kind' => $tier['type'],
                'value' => self::decimal((int) $tier['value']),
            ], (array) ($promotion->config['tiers'] ?? [])),
            'category_ulids' => $promotion->conditions['category_ulids'] ?? [],
            'priority' => $promotion->priority,
            'starts_at' => $promotion->starts_at,
            'ends_at' => $promotion->ends_at,
            'is_active' => $promotion->is_active,
        ];
    }

    public static function describe(Promotion $promotion): string
    {
        $currency = app(TenantSettings::class)->string('store.currency', 'PKR');
        $config = $promotion->config;

        return match ($promotion->type) {
            'percentage_sale' => self::decimal((int) ($config['percent_bps'] ?? 0)).'% off',
            'buy_x_get_y' => 'Buy '.($config['buy'] ?? '?').', get '.($config['get'] ?? '?').' at '.self::decimal((int) ($config['percent_bps'] ?? 0)).'% off',
            default => implode('; ', array_map(fn (array $tier): string => 'from '.Money::of((int) $tier['min_spend'], $currency)->format().': '
                .($tier['type'] === 'percentage' ? self::decimal((int) $tier['value']).'% off' : Money::of((int) $tier['value'], $currency)->format().' off'),
                (array) ($config['tiers'] ?? []))),
        };
    }

    /**
     * "12.5" → 1250: percent to basis points, rupees to paisa (both two decimal places).
     */
    private static function hundredths(string $value): int
    {
        $value = str_replace(',', '', trim($value));
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function decimal(int $hundredths): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100), '0'), '.');
    }

    public static function getPages(): array
    {
        return ['index' => ListPromotionsPage::route('/')];
    }
}
