<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Modules\Content\Actions\ChangePageStatus;
use Modules\Content\Actions\SavePage;
use Modules\Content\Models\Page;

final class EditPagePage extends EditRecord
{
    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Page $record */
        return app(SavePage::class)->handle(
            $record,
            (string) $data['title'],
            $record->slug === Page::HOME ? Page::HOME : ($data['slug'] ?? null),
            array_values((array) ($data['sections'] ?? [])),
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
            PageResource::actor(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')->icon(Heroicon::OutlinedEye)->color('success')
                ->visible(fn (): bool => $this->record instanceof Page && $this->record->status !== 'published')
                ->action(function (): void {
                    /** @var Page $page */
                    $page = $this->getRecord();
                    app(ChangePageStatus::class)->handle($page, 'published', PageResource::actor());
                }),
            Action::make('unpublish')->label('Back to draft')->icon(Heroicon::OutlinedEyeSlash)->color('gray')
                ->visible(fn (): bool => $this->record instanceof Page && $this->record->status === 'published' && $this->record->slug !== Page::HOME)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var Page $page */
                    $page = $this->getRecord();
                    app(ChangePageStatus::class)->handle($page, 'draft', PageResource::actor());
                }),
        ];
    }
}
