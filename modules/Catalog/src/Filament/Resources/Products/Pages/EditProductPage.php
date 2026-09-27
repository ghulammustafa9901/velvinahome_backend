<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Filament\Resources\Products\ProductResource;
use Modules\Catalog\Filament\StaffAccess;
use Modules\Catalog\Models\Product;

final class EditProductPage extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();
        $product->loadMissing(['brand', 'categories']);

        return $data + [
            'brand' => $product->brand?->ulid,
            'categories' => $product->categories->sortByDesc(fn ($c) => (int) $c->getRelationValue('pivot')?->getAttribute('is_primary'))->pluck('ulid')->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        // The form has already validated these fields; an empty category list here really
        // means "no categories", so the DTO is built directly rather than re-validated.
        return app(UpdateProductDetails::class)->handle($record, new ProductDetailsData(
            title: (string) ($data['title'] ?? $record->title),
            slug: (string) ($data['slug'] ?? $record->slug),
            description: $data['description'] ?? null,
            shortDescription: $data['short_description'] ?? null,
            brandUlid: $data['brand'] ?? null,
            categoryUlids: array_values($data['categories'] ?? []),
            tags: array_values($data['tags'] ?? []),
            specifications: $record->specifications,
        ), StaffAccess::actor());
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->statusAction('publish', 'Publish', ProductStatus::Active, 'success', Heroicon::OutlinedEye, 'products.publish'),
            $this->statusAction('unpublish', 'Back to draft', ProductStatus::Draft, 'gray', Heroicon::OutlinedEyeSlash, 'products.manage'),
            $this->statusAction('archive', 'Archive', ProductStatus::Archived, 'warning', Heroicon::OutlinedArchiveBox, 'products.manage'),
        ];
    }

    private function statusAction(string $name, string $label, ProductStatus $target, string $color, Heroicon $icon, string $permission): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->requiresConfirmation()
            ->visible(fn (): bool => StaffAccess::can($permission) && $this->record instanceof Product && $this->record->status !== $target)
            ->action(function () use ($target): void {
                /** @var Product $product */
                $product = $this->getRecord();
                app(ChangeProductStatus::class)->handle($product, $target, StaffAccess::actor());
                $this->refreshFormData(['status']);
            });
    }
}
