<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Companies;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Platform\Filament\Resources\Companies\Pages\CreateCompanyPage;
use Modules\Platform\Filament\Resources\Companies\Pages\ListCompaniesPage;
use Modules\Platform\Models\Company;
use Modules\Platform\Models\PlatformUser;

/**
 * Companies — the contracting parties — each with its brands (spec §4.9).
 */
final class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        $user = auth('platform')->user();

        return $user instanceof PlatformUser && $user->role->canManageTenants();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->required()->maxLength(200),
            TextInput::make('legal_name')->required()->maxLength(200),
            TextInput::make('slug')->required()->maxLength(60)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->helperText('Lower-case letters, digits and hyphens.'),
            TextInput::make('contact_name')->maxLength(200),
            TextInput::make('contact_email')->email(),
            TextInput::make('contact_phone')->tel(),
            TextInput::make('country_code')->default('PK')->length(2)->required(),
            Select::make('contract_status')->options([
                'prospect' => 'Prospect', 'active' => 'Active', 'suspended' => 'Suspended', 'ended' => 'Ended',
            ])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('tenants'))
            ->columns([
                TextColumn::make('display_name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('contract_status')->badge(),
                TextColumn::make('tenants_count')->label('Brands')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('display_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompaniesPage::route('/'),
            'create' => CreateCompanyPage::route('/create'),
        ];
    }
}
