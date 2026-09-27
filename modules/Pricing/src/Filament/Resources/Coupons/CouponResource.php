<?php

declare(strict_types=1);

namespace Modules\Pricing\Filament\Resources\Coupons;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
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
use Modules\Pricing\Actions\SaveCoupon;
use Modules\Pricing\Data\CouponData;
use Modules\Pricing\Models\Coupon;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;
use UnitEnum;

/**
 * Coupon codes. Percentages are entered as percent and stored as basis points; amounts are
 * entered in rupees and stored in paisa — never as floats.
 */
final class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    public static function staffCan(string $permission): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can($permission);
    }

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::staffCan('promotions.manage');
    }

    public static function canCreate(): bool
    {
        return self::staffCan('promotions.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->weight('bold')->copyable(),
                TextColumn::make('discount_type')->label('Type')->badge(),
                TextColumn::make('value')->formatStateUsing(fn (int $state, Coupon $record): string => self::describeValue($record)),
                TextColumn::make('used_count')->label('Used')
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $state.($record->usage_limit === null ? '' : ' / '.$record->usage_limit)),
                TextColumn::make('ends_at')->dateTime()->placeholder('No end'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (Coupon $record): array => self::toForm($record))
                    ->schema(self::fields())
                    ->action(fn (Coupon $record, array $data) => app(SaveCoupon::class)->handle($record, self::toData($data, $record), self::actor())),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('code')->required()->regex('/^[A-Za-z0-9_-]{3,50}$/')->helperText('Customers type this at checkout; not case-sensitive.'),
            Select::make('discount_type')->required()->live()->options([
                'percentage' => 'Percentage off',
                'fixed' => 'Fixed amount off',
                'free_shipping' => 'Free shipping',
            ]),
            TextInput::make('value')->label(fn (Get $get): string => $get('discount_type') === 'percentage' ? 'Percent off' : 'Amount off (Rs)')
                ->visible(fn (Get $get): bool => in_array($get('discount_type'), ['percentage', 'fixed'], true))
                ->required(fn (Get $get): bool => in_array($get('discount_type'), ['percentage', 'fixed'], true))
                ->regex('/^\d[\d,]*(\.\d{1,2})?$/'),
            TextInput::make('minimum_spend')->label('Minimum spend (Rs)')->regex('/^\d[\d,]*(\.\d{1,2})?$/'),
            TextInput::make('usage_limit')->numeric()->integer()->minValue(1)->helperText('Total uses across all customers.'),
            TextInput::make('usage_limit_per_customer')->numeric()->integer()->minValue(1),
            DateTimePicker::make('starts_at'),
            DateTimePicker::make('ends_at')->after('starts_at'),
            Toggle::make('is_active')->default(true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toForm(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'value' => match ($coupon->discount_type) {
                'percentage' => self::decimal($coupon->value),
                'fixed' => self::decimal($coupon->value),
                default => null,
            },
            'minimum_spend' => $coupon->minimum_spend_amount === null ? null : self::decimal($coupon->minimum_spend_amount),
            'usage_limit' => $coupon->usage_limit,
            'usage_limit_per_customer' => $coupon->usage_limit_per_customer,
            'starts_at' => $coupon->starts_at,
            'ends_at' => $coupon->ends_at,
            'is_active' => $coupon->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function toData(array $data, ?Coupon $existing = null): CouponData
    {
        $type = (string) $data['discount_type'];
        $conditions = $existing === null ? [] : $existing->conditions;

        return new CouponData(
            code: (string) $data['code'],
            discountType: $type,
            // Both percent (→ basis points) and rupees (→ paisa) have two decimal places.
            value: in_array($type, ['percentage', 'fixed'], true) ? self::minorUnits((string) $data['value']) : 0,
            minimumSpendAmount: blank($data['minimum_spend'] ?? null) ? null : self::minorUnits((string) $data['minimum_spend']),
            usageLimit: blank($data['usage_limit'] ?? null) ? null : (int) $data['usage_limit'],
            usageLimitPerCustomer: blank($data['usage_limit_per_customer'] ?? null) ? null : (int) $data['usage_limit_per_customer'],
            startsAt: blank($data['starts_at'] ?? null) ? null : CarbonImmutable::parse((string) $data['starts_at']),
            endsAt: blank($data['ends_at'] ?? null) ? null : CarbonImmutable::parse((string) $data['ends_at']),
            productUlids: $conditions['product_ulids'] ?? [],
            categoryUlids: $conditions['category_ulids'] ?? [],
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    private static function minorUnits(string $value): int
    {
        return Money::parse(str_replace(',', '', $value), self::currency())->amount;
    }

    private static function currency(): string
    {
        return app(TenantSettings::class)->string('store.currency', 'PKR');
    }

    private static function decimal(int $minor): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($minor, 100), $minor % 100), '0'), '.');
    }

    public static function describeValue(Coupon $coupon): string
    {
        return match ($coupon->discount_type) {
            'percentage' => self::decimal($coupon->value).'%',
            'fixed' => Money::of($coupon->value, self::currency())->format(),
            default => 'Free shipping',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListCouponsPage::route('/')];
    }
}
