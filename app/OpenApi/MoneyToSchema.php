<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Modules\Support\ValueObjects\Money;

/**
 * Every Money in a response is the same documented object (api-conventions §2), as a shared
 * `Money` component, so the generated TypeScript client has one Money type.
 */
final class MoneyToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(Money::class);
    }

    public function toSchema(Type $type): OpenApi\ObjectType
    {
        return (new OpenApi\ObjectType)
            ->addProperty('amount', (new OpenApi\IntegerType)->setDescription('Minor units (paisa for PKR). Never a float.'))
            ->addProperty('currency', (new OpenApi\StringType)->setDescription('ISO 4217 code, e.g. PKR.'))
            ->addProperty('formatted', (new OpenApi\StringType)->setDescription('Display string formatted by the server, e.g. "Rs 2,499".'))
            ->setRequired(['amount', 'currency', 'formatted']);
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('schemas', Money::class, $this->components, 'Money');
    }
}
