<?php

declare(strict_types=1);

namespace Modules\Customers\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Customers\Data\AddressData;
use Modules\Customers\Models\CustomerAddress;
use Modules\Support\Contracts\Action;

/**
 * Adds or edits an address in a customer's book. The customer id comes from the
 * authenticated token, never from input, and edits are scoped to that customer.
 */
final class SaveAddress implements Action
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(int $customerId, ?string $addressUlid, AddressData $data, ?string $label = null, bool $defaultShipping = false, bool $defaultBilling = false): CustomerAddress
    {
        return $this->db->connection()->transaction(function () use ($customerId, $addressUlid, $data, $label, $defaultShipping, $defaultBilling): CustomerAddress {
            $address = $addressUlid === null
                ? new CustomerAddress(['customer_id' => $customerId])
                : CustomerAddress::query()->where('customer_id', $customerId)->where('ulid', $addressUlid)->firstOrFail();

            $isFirst = ! CustomerAddress::query()->where('customer_id', $customerId)->exists();

            foreach (['is_default_shipping' => $defaultShipping || $isFirst, 'is_default_billing' => $defaultBilling || $isFirst] as $flag => $on) {
                if ($on) {
                    CustomerAddress::query()->where('customer_id', $customerId)->update([$flag => false]);
                }
            }

            $address->fill($data->toColumns() + [
                'label' => $label,
                'is_default_shipping' => $defaultShipping || $isFirst || ($address->exists && $address->is_default_shipping),
                'is_default_billing' => $defaultBilling || $isFirst || ($address->exists && $address->is_default_billing),
            ])->save();

            return $address;
        });
    }
}
