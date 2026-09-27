<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Customers\Data\AddressData;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $customer_id
 * @property string|null $label
 * @property bool $is_default_shipping
 * @property bool $is_default_billing
 */
final class CustomerAddress extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default_shipping' => 'boolean', 'is_default_billing' => 'boolean'];
    }

    public function toData(): AddressData
    {
        return AddressData::fromColumns($this->getAttributes());
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return ['id' => $this->ulid, 'label' => $this->label]
            + $this->toData()->toColumns()
            + ['is_default_shipping' => $this->is_default_shipping, 'is_default_billing' => $this->is_default_billing];
    }
}
