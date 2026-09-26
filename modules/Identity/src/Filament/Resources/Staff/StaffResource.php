<?php

declare(strict_types=1);

namespace Modules\Identity\Filament\Resources\Staff;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Identity\Enums\StaffPermission;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Filament\Resources\Staff\Pages\CreateStaffPage;
use Modules\Identity\Filament\Resources\Staff\Pages\EditStaffPage;
use Modules\Identity\Filament\Resources\Staff\Pages\ListStaffPage;
use Modules\Identity\Models\StaffUser;
use UnitEnum;

/**
 * Staff and roles of the current brand (spec §6.12). Visibility follows permissions; every
 * write goes through CreateStaffMember / UpdateStaffMember, which check them again.
 */
final class StaffResource extends Resource
{
    protected static ?string $model = StaffUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        return self::holds(StaffPermission::StaffView);
    }

    public static function canCreate(): bool
    {
        return self::holds(StaffPermission::StaffManage);
    }

    public static function canEdit(Model $record): bool
    {
        return self::holds(StaffPermission::StaffManage);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('email')->email()->required()->disabledOn('edit'),
            Select::make('role')->required()->options(collect(StaffRole::cases())->mapWithKeys(
                fn (StaffRole $role): array => [$role->value => $role->value],
            )->all()),
            Select::make('status')->required()->default('active')->options(['active' => 'Active', 'disabled' => 'Disabled']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->label('Role')->badge(),
                TextColumn::make('status')->badge(),
                IconColumn::make('two_factor_confirmed_at')->label('2FA')->boolean()
                    ->state(fn (StaffUser $record): bool => $record->two_factor_confirmed_at !== null),
                TextColumn::make('last_login_at')->since()->placeholder('never'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffPage::route('/'),
            'create' => CreateStaffPage::route('/create'),
            'edit' => EditStaffPage::route('/{record}/edit'),
        ];
    }

    private static function holds(StaffPermission $permission): bool
    {
        $user = auth('staff')->user();

        return $user instanceof StaffUser && $user->can($permission->value);
    }
}
