<?php

declare(strict_types=1);

namespace Modules\Notifications\Filament\Resources\Templates;

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
use Modules\Notifications\Actions\SaveNotificationTemplate;
use Modules\Notifications\Models\NotificationTemplate;
use UnitEnum;

final class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Customer emails';

    protected static ?string $slug = 'notification-templates';

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
                TextColumn::make('key')->label('Sent when')->formatStateUsing(fn (string $state): string => ucfirst(str_replace('order.', 'order ', $state))),
                TextColumn::make('subject')->wrap(),
                IconColumn::make('is_enabled')->boolean()->label('On'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (NotificationTemplate $record): array => $record->only(['subject', 'intro', 'is_enabled']))
                    ->schema([
                        TextInput::make('subject')->required()->maxLength(200),
                        Textarea::make('intro')->required()->rows(4)->maxLength(2000)
                            ->helperText('Placeholders: '.implode(', ', array_map(fn (string $name): string => '{{ '.$name.' }}', NotificationTemplate::PLACEHOLDERS))),
                        Toggle::make('is_enabled')->label('Send this email'),
                    ])
                    ->action(fn (NotificationTemplate $record, array $data) => app(SaveNotificationTemplate::class)->handle(
                        $record, (string) $data['subject'], (string) $data['intro'], (bool) $data['is_enabled'], self::actor(),
                    )),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationTemplatesPage::route('/')];
    }
}
