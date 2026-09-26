<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Tenants;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Actions\StartImpersonation;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Filament\Resources\Tenants\Pages\CreateTenantPage;
use Modules\Platform\Filament\Resources\Tenants\Pages\ListTenantsPage;
use Modules\Platform\Models\Company;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;

/**
 * Brands (tenants): company, status, domains, schema version, health, last backup — and the
 * lifecycle operations, all of which call the same Actions as the Artisan commands (spec
 * §4.9).
 */
final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'brand';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return self::actor()?->role->canManageTenants() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company')->label('Company')->required()
                ->options(fn (): array => Company::query()->orderBy('display_name')->pluck('display_name', 'slug')->all()),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('slug')->required()->maxLength(40)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->helperText('Names the database and its roles. Cannot be changed later.'),
            TextInput::make('storefront_host')->required()->placeholder('velvinahome.com'),
            TextInput::make('admin_host')->required()->placeholder('admin.velvinahome.com'),
            TextInput::make('api_host')->required()->placeholder('api.velvinahome.com'),
            TextInput::make('owner_name')->required(),
            TextInput::make('owner_email')->email()->required(),
            Select::make('plan')->options(fn (): array => Plan::query()->pluck('name', 'key')->all()),
            TextInput::make('currency')->default('PKR')->length(3)->required(),
            TextInput::make('timezone')->default('Asia/Karachi')->required(),
            TextInput::make('theme_id')->default('default')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['company', 'domains']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('company.display_name')->label('Company')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (Tenant $record): string => match ($record->status) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Suspended => 'warning',
                        TenantStatus::Provisioning => 'info',
                        TenantStatus::Archived => 'gray',
                    }),
                TextColumn::make('domains.hostname')->label('Hosts')->listWithLineBreaks()->limitList(3),
                TextColumn::make('schema_version')->label('Schema')->toggleable(),
                TextColumn::make('last_backup_at')->since()->label('Last backup')->placeholder('never'),
                TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TenantStatus::cases())->mapWithKeys(
                    fn (TenantStatus $status): array => [$status->value => ucfirst($status->value)],
                )->all()),
            ])
            ->recordActions([
                Action::make('supportAccess')
                    ->label('Support access')
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Active)
                    ->schema([
                        Textarea::make('reason')->required()->minLength(StartImpersonation::MIN_REASON_LENGTH)
                            ->helperText('Recorded in the platform audit log and in the brand\'s own activity log.'),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        $actor = self::actor();
                        abort_if($actor === null, 403);

                        $grant = app(StartImpersonation::class)->handle(
                            $record, $actor, (string) $data['reason'], request()->ip(), request()->userAgent(),
                        );

                        Notification::make()
                            ->title('Support session ready')
                            ->body('Open within '.config('platform.impersonation.ttl_minutes').' minutes: '.$grant['url'])
                            ->persistent()
                            ->success()
                            ->send();
                    }),
                Action::make('suspend')
                    ->color('warning')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Active && self::canCreate())
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (Tenant $record, array $data) => app(ChangeTenantStatus::class)
                        ->handle($record, TenantStatus::Suspended, (string) $data['reason'], self::actor())),
                Action::make('activate')
                    ->color('success')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Suspended && self::canCreate())
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => app(ChangeTenantStatus::class)
                        ->handle($record, TenantStatus::Active, null, self::actor())),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantsPage::route('/'),
            'create' => CreateTenantPage::route('/create'),
        ];
    }

    private static function actor(): ?PlatformUser
    {
        $user = auth('platform')->user();

        return $user instanceof PlatformUser ? $user : null;
    }
}
