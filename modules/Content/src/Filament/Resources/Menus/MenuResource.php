<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Menus;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\SaveMenu;
use Modules\Content\Models\Menu;
use UnitEnum;

final class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::actor()?->can('content.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('key')->required()->regex('/^[a-z][a-z0-9_-]{1,49}$/')->helperText('The storefront asks for menus by key: main, footer…'),
            TextInput::make('name')->required()->maxLength(120),
            Repeater::make('items')->maxItems(50)->collapsible()->schema([
                TextInput::make('label')->required()->maxLength(60),
                TextInput::make('url')->label('Link')->required()->maxLength(2048)->placeholder('/categories/bedding'),
                Repeater::make('children')->label('Sub-items')->maxItems(20)->schema([
                    TextInput::make('label')->required()->maxLength(60),
                    TextInput::make('url')->label('Link')->required()->maxLength(2048),
                ])->columns(2),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('key')->badge(),
                TextColumn::make('items')->label('Items')->formatStateUsing(fn (Menu $record): string => (string) count($record->items)),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (Menu $record): array => ['key' => $record->key, 'name' => $record->name, 'items' => $record->items])
                    ->schema(self::fields())
                    ->action(fn (Menu $record, array $data) => app(SaveMenu::class)->handle(
                        $record, (string) $data['key'], (string) $data['name'], array_values((array) ($data['items'] ?? [])), self::actor(),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMenusPage::route('/')];
    }
}
