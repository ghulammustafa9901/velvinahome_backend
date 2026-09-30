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
     * @return array{id: string, label: string|null, first_name: string, last_name: string, phone: string, line1: string, line2: string|null, city: string, province: string|null, postal_code: string|null, country_code: string, is_default_shipping: bool, is_default_billing: bool}
     */
    public function toApi(): array
    {
        $address = $this->toData();

        return [
            'id' => $this->ulid,
            'label' => $this->label,
            'first_name' => $address->firstName,
            'last_name' => $address->lastName,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'province' => $address->province,
            'postal_code' => $address->postalCode,
            'country_code' => strtoupper($address->countryCode),
            'is_default_shipping' => $this->is_default_shipping,
            'is_default_billing' => $this->is_default_billing,
        ];
    }
}
