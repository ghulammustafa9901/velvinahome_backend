<?php

declare(strict_types=1);

namespace Modules\Support\ValueObjects;

use JsonSerializable;

/**
 * A string → string map in an API response (variant options, design tokens). Always encodes
 * as a JSON object — an empty PHP array would otherwise become `[]` — and is documented as
 * `{ [key: string]: string }` in the OpenAPI contract.
 */
final readonly class StringMap implements JsonSerializable
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(public array $values) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function from(array $values): self
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $clean[(string) $key] = (string) $value;
            }
        }

        return new self($clean);
    }

    public function jsonSerialize(): object
    {
        return (object) $this->values;
    }
}
