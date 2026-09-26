<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;

/**
 * @property int $id
 * @property string $ulid
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $title
 * @property string $option_signature
 * @property Money $price
 * @property Money|null $compare_at_price
 * @property Money|null $cost
 * @property int|null $weight_grams
 * @property bool $allow_backorder
 * @property bool $is_default
 * @property int $position
 * @property-read Product $product
 */
final class ProductVariant extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'compare_at_price' => MoneyCast::class,
            'cost' => MoneyCast::class,
            'weight_grams' => 'integer',
            'allow_backorder' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsToMany<ProductOptionValue, $this>
     */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'variant_option_values');
    }
}
