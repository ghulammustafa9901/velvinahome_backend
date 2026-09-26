<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_option_id
 * @property string $value
 * @property int $position
 * @property-read ProductOption $option
 */
final class ProductOptionValue extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<ProductOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
