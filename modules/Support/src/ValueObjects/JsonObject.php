<?php

declare(strict_types=1);

namespace Modules\Support\ValueObjects;

use JsonSerializable;

/**
 * A free-form JSON object in an API response whose shape depends on another field (e.g. a
 * page section's `data`, described by its `type`). Always encodes as an object.
 */
final readonly class JsonObject implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public array $values) {}

    public function jsonSerialize(): object
    {
        return (object) $this->values;
    }
}
