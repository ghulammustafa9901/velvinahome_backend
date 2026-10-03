<?php

declare(strict_types=1);

namespace Modules\Payments\Filament\Resources\PaymentMethods;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\UpdatePaymentMethod;
use Modules\Payments\Models\PaymentMethod;
use UnitEnum;

/**
 * The payment methods the store offers. COD and manual transfer at launch; online gateways
 * appear here as adapters are added.
 */
final class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payment methods';

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
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->description(fn (PaymentMethod $record): string => $record->code),
                TextColumn::make('description')->wrap()->limit(80),
                IconColumn::make('is_enabled')->boolean()->label('Enabled'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (PaymentMethod $record): array => [
                        'is_enabled' => $record->is_enabled,
                        'label' => $record->label,
                        'description' => $record->description,
                        'instructions' => $record->config['instructions'] ?? null,
                    ])
                    ->schema(fn (PaymentMethod $record): array => array_values(array_filter([
                        Toggle::make('is_enabled')->label('Offer at checkout'),
                        TextInput::make('label')->required()->maxLength(120),
                        Textarea::make('description')->maxLength(500),
                        $record->code === 'manual_transfer'
                            ? Textarea::make('instructions')->label('Account details shown to the customer')->rows(5)->maxLength(2000)
                                ->helperText('Bank, account title, IBAN / wallet number. Shown after the order is placed.')
                            : null,
                    ])))
                    ->action(fn (PaymentMethod $record, array $data) => app(UpdatePaymentMethod::class)->handle(
                        $record,
                        (bool) $data['is_enabled'],
                        (string) $data['label'],
                        $data['description'] ?? null,
                        $data['instructions'] ?? null,
                        self::actor(),
                    )),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentMethodsPage::route('/')];
    }
}
