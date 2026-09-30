<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Modules\Support\ValueObjects\JsonObject;
use Modules\Support\ValueObjects\StringMap;

/**
 * StringMap → `{ [key: string]: string }`; JsonObject → a free-form object.
 */
final class StringMapToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && ($type->isInstanceOf(StringMap::class) || $type->isInstanceOf(JsonObject::class));
    }

    public function toSchema(Type $type): OpenApi\ObjectType
    {
        $schema = new OpenApi\ObjectType;

        return $type->isInstanceOf(StringMap::class) ? $schema->additionalProperties(new OpenApi\StringType) : $schema;
    }
}
