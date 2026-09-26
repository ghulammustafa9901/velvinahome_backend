<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Concerns\HasUlid;

/**
 * A curated (manual) or rule-based product grouping, e.g. "Summer Sale".
 *
 * @property int $id
 * @property string $ulid
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property bool $is_dynamic
 * @property array<string, mixed>|null $rules
 * @property bool $is_visible
 */
final class Collection extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_dynamic' => 'boolean', 'rules' => 'array', 'is_visible' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product')->withPivot('position')->orderByPivot('position');
    }
}
