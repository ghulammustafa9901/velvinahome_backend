<?php

declare(strict_types=1);

namespace Modules\Reviews\Filament\Resources\Reviews;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Contracts\ProductDirectory;
use Modules\Reviews\Actions\ModerateReview;
use Modules\Reviews\Models\Review;
use UnitEnum;

final class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return self::actor()?->can('reviews.moderate') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_ulid')->label('Product')->formatStateUsing(fn (string $state): string => app(ProductDirectory::class)->titleOf($state) ?? $state),
                TextColumn::make('rating')->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
                TextColumn::make('title')->placeholder('—')->description(fn (Review $record): string => mb_strimwidth($record->body, 0, 120, '…'))->wrap(),
                TextColumn::make('author_name')->label('By'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('pending')])
            ->recordActions([
                Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (Review $record): bool => $record->status !== 'approved')
                    ->action(fn (Review $record) => app(ModerateReview::class)->handle($record, 'approved', self::actor())),
                Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (Review $record): bool => $record->status !== 'rejected')
                    ->action(fn (Review $record) => app(ModerateReview::class)->handle($record, 'rejected', self::actor())),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListReviewsPage::route('/')];
    }
}
