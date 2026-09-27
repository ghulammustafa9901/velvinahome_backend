<?php

declare(strict_types=1);

namespace Modules\Customers\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A postal address as the storefront sends it and as orders freeze it. Shared by Customers
 * (address book), Shipping (zone matching), Tax (rates) and Orders (snapshots).
 */
#[MapInputName(SnakeCaseMapper::class)]
final class AddressData extends Data
{
    public function __construct(
        #[Max(100)]
        public readonly string $firstName,
        #[Max(100)]
        public readonly string $lastName,
        #[Regex('/^\+?[0-9 ()-]{7,20}$/')]
        public readonly string $phone,
        #[Max(255)]
        public readonly string $line1,
        #[Max(100)]
        public readonly string $city,
        #[Max(255)]
        public readonly ?string $line2 = null,
        #[Max(100)]
        public readonly ?string $province = null,
        #[Max(20)]
        public readonly ?string $postalCode = null,
        #[Size(2)]
        public readonly string $countryCode = 'PK',
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toColumns(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postalCode,
            'country_code' => strtoupper($this->countryCode),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromColumns(array $row): self
    {
        return new self(
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            phone: (string) $row['phone'],
            line1: (string) $row['line1'],
            city: (string) $row['city'],
            line2: $row['line2'] ?? null,
            province: $row['province'] ?? null,
            postalCode: $row['postal_code'] ?? null,
            countryCode: (string) ($row['country_code'] ?? 'PK'),
        );
    }
}
