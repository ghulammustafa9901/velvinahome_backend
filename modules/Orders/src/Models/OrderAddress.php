<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $country_code
 */
final class OrderAddress extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array{first_name: string, last_name: string, phone: string, line1: string, line2: string|null, city: string, province: string|null, postal_code: string|null, country_code: string}
     */
    public function toApi(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
        ];
    }
}
