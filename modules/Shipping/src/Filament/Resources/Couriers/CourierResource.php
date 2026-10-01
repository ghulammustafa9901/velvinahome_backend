<?php

declare(strict_types=1);

namespace Modules\Shipping\Filament\Resources\Couriers;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Shipping\Actions\SaveCourier;
use Modules\Shipping\Models\Courier;
use Modules\Shipping\Support\CourierAdapters;
use UnitEnum;

final class CourierResource extends Resource
{
    protected static ?string $model = Courier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Couriers';

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
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('adapter')->label('Integration')->formatStateUsing(fn (string $state): string => app(CourierAdapters::class)->options()[$state] ?? $state),
                TextColumn::make('tracking_url_template')->label('Tracking link')->placeholder('Not set')->limit(50),
                IconColumn::make('is_enabled')->boolean()->label('In use'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (Courier $record): array => ['name' => $record->name, 'adapter' => $record->adapter, 'tracking_url_template' => $record->tracking_url_template, 'is_enabled' => $record->is_enabled, 'position' => $record->position])
                    ->schema(self::fields())
                    ->action(fn (Courier $record, array $data) => self::save($record, $data)),
            ])
            ->defaultSort('position');
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(64),
            Select::make('adapter')->label('Integration')->required()->default('manual')->options(fn (): array => app(CourierAdapters::class)->options()),
            TextInput::make('tracking_url_template')->label('Tracking link')->maxLength(2048)
                ->placeholder('https://courier.example/track?cn={tracking}')
                ->helperText('From your courier. {tracking} is replaced by the tracking number; customers see this link.'),
            TextInput::make('position')->numeric()->integer()->default(0),
            Toggle::make('is_enabled')->label('In use')->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Courier $courier, array $data): Courier
    {
        return app(SaveCourier::class)->handle(
            $courier,
            (string) $data['name'],
            (string) ($data['adapter'] ?? 'manual'),
            $data['tracking_url_template'] ?? null,
            (bool) ($data['is_enabled'] ?? true),
            (int) ($data['position'] ?? 0),
            null,
            self::actor(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ListCouriersPage::route('/')];
    }
}
