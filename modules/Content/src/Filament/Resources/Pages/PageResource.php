<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Pages;

use BackedEnum;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Models\Page;
use UnitEnum;

/**
 * Page builder: pages are ordered sections of fixed types (Content\Support\Sections), edited
 * as blocks. The same rules validate the save in SavePage.
 */
final class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

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
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255),
                TextInput::make('slug')->maxLength(120)->helperText('Web address after /pages/. Leave empty to use the title.')
                    ->disabled(fn (?Page $record): bool => $record?->slug === Page::HOME),
                TextInput::make('meta_title')->label('Search title')->maxLength(255),
                Textarea::make('meta_description')->label('Search description')->maxLength(320)->rows(2),
            ]),
            Builder::make('sections')->columnSpanFull()->collapsible()->blockNumbers(false)->blocks([
                Block::make('hero')->icon(Heroicon::OutlinedSparkles)->schema([
                    TextInput::make('heading')->required()->maxLength(120),
                    TextInput::make('subheading')->maxLength(300),
                    TextInput::make('image_url')->label('Image URL')->maxLength(2048),
                    TextInput::make('cta_label')->label('Button text')->maxLength(40),
                    TextInput::make('cta_url')->label('Button link')->maxLength(2048)->placeholder('/products'),
                ]),
                Block::make('rich_text')->label('Text')->icon(Heroicon::OutlinedBars3BottomLeft)->schema([
                    TextInput::make('heading')->maxLength(120),
                    Textarea::make('body')->required()->rows(8)->maxLength(20000),
                ]),
                Block::make('product_grid')->label('Product grid')->icon(Heroicon::OutlinedSquares2x2)->schema([
                    TextInput::make('title')->maxLength(120),
                    Select::make('source')->required()->default('newest')->options([
                        'newest' => 'Newest products', 'category' => 'A category', 'collection' => 'A collection', 'brand' => 'A brand',
                    ]),
                    TextInput::make('value')->label('Category / collection / brand slug')->maxLength(120),
                    TextInput::make('limit')->numeric()->integer()->minValue(1)->maxValue(24)->default(8)->required(),
                ]),
                Block::make('category_grid')->label('Category grid')->icon(Heroicon::OutlinedRectangleGroup)->schema([
                    TextInput::make('title')->maxLength(120),
                    TagsInput::make('category_slugs')->label('Category slugs')->required(),
                ]),
                Block::make('banner')->icon(Heroicon::OutlinedPhoto)->schema([
                    TextInput::make('image_url')->label('Image URL')->required()->maxLength(2048),
                    TextInput::make('alt')->label('Alt text')->required()->maxLength(200),
                    TextInput::make('link_url')->label('Link')->maxLength(2048),
                ]),
                Block::make('faq')->label('FAQ')->icon(Heroicon::OutlinedQuestionMarkCircle)->schema([
                    TextInput::make('title')->maxLength(120),
                    Repeater::make('items')->required()->minItems(1)->maxItems(30)->schema([
                        TextInput::make('question')->required()->maxLength(300),
                        Textarea::make('answer')->required()->maxLength(3000),
                    ]),
                ]),
                Block::make('newsletter')->icon(Heroicon::OutlinedEnvelope)->schema([
                    TextInput::make('heading')->required()->maxLength(120),
                    TextInput::make('text')->maxLength(300),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('slug')->formatStateUsing(fn (string $state, Page $record): string => $record->path()),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPagesPage::route('/'),
            'create' => CreatePagePage::route('/create'),
            'edit' => EditPagePage::route('/{record}/edit'),
        ];
    }
}
