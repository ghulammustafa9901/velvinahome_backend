<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property bool $is_visible
 * @property-read Category|null $parent
 */
final class Category extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Every ancestor, nearest last, through the closure table (one query at any depth).
     *
     * @return BelongsToMany<Category, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'category_closure', 'descendant_id', 'ancestor_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'desc');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function descendantsAndSelf(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'category_closure', 'ancestor_id', 'descendant_id')
            ->withPivot('depth');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_category');
    }
}
