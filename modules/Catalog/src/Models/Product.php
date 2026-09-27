<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Support\ProductSearch;
use Modules\Support\Concerns\HasUlid;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $ulid
 * @property int|null $brand_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string|null $short_description
 * @property ProductStatus $status
 * @property list<string> $tags
 * @property array<string, mixed> $specifications
 * @property Carbon|null $published_at
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Brand|null $brand
 */
final class Product extends Model implements HasMedia
{
    use HasUlid;
    use InteractsWithMedia;
    use ProductSearch;
    use SoftDeletes;

    public const IMAGE_CONVERSIONS = ['thumb' => 400, 'card' => 800, 'large' => 1600];

    protected $guarded = ['id'];

    /**
     * Product images: raster only (never SVG from uploads, spec §6.9), stored under the
     * tenant's media prefix, with WebP conversions generated on the `media` queue.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(['image/webp', 'image/jpeg', 'image/png', 'image/avif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::IMAGE_CONVERSIONS as $name => $width) {
            $this->addMediaConversion($name)
                ->performOnCollections('images')
                ->width($width)
                ->format('webp')
                ->quality(82);
        }
    }

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'tags' => 'array',
            'specifications' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::Active && $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')->withPivot('is_primary');
    }

    /**
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_product')->withPivot('position');
    }
}
