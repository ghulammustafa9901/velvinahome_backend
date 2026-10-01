<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Articles;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\SaveArticle;
use Modules\Content\Models\Article;
use UnitEnum;

final class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Blog';

    protected static ?string $modelLabel = 'post';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->weight('bold')->description(fn (Article $record): string => $record->path()),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('author_name')->label('Author')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)->slideOver()
                    ->fillForm(fn (Article $record): array => [
                        'title' => $record->title, 'slug' => $record->slug, 'excerpt' => $record->excerpt, 'body' => $record->body,
                        'cover_image_url' => $record->cover_image_url, 'author_name' => $record->author_name, 'tags' => $record->tags,
                        'published' => $record->status === 'published', 'meta_title' => $record->meta_title, 'meta_description' => $record->meta_description,
                    ])
                    ->schema(self::fields())
                    ->action(fn (Article $record, array $data) => self::save($record, $data)),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->label('Address')->prefix('/blog/')->maxLength(120)->helperText('Leave empty to use the title.'),
            Textarea::make('excerpt')->rows(2)->maxLength(500)->helperText('Shown on the blog list and in search results.'),
            Textarea::make('body')->required()->rows(16)->maxLength(100000)->helperText('Plain text. Leave an empty line between paragraphs.'),
            TextInput::make('cover_image_url')->label('Cover image address')->maxLength(2048)->helperText('/path on this site, or https://…'),
            TextInput::make('author_name')->maxLength(120),
            TagsInput::make('tags')->nestedRecursiveRules(['max:40']),
            TextInput::make('meta_title')->label('Search title')->maxLength(255),
            Textarea::make('meta_description')->label('Search description')->rows(2)->maxLength(320),
            Toggle::make('published')->label('Published'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Article $article, array $data): Article
    {
        return app(SaveArticle::class)->handle(
            $article,
            (string) $data['title'],
            $data['slug'] ?? null,
            (string) $data['body'],
            $data['excerpt'] ?? null,
            $data['cover_image_url'] ?? null,
            $data['author_name'] ?? null,
            array_values(array_map('strval', (array) ($data['tags'] ?? []))),
            (bool) ($data['published'] ?? false),
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
            self::actor(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ListArticlesPage::route('/')];
    }
}
