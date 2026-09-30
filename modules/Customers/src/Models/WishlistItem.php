<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $customer_id
 * @property string $product_ulid
 * @property string|null $variant_ulid
 * @property Carbon $created_at
 */
final class WishlistItem extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array{id: string, product_id: string, variant_id: string|null, added_at: string}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->ulid,
            'product_id' => $this->product_ulid,
            'variant_id' => $this->variant_ulid,
            'added_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
