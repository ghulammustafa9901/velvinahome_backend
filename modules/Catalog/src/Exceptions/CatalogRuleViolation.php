<?php

declare(strict_types=1);

namespace Modules\Catalog\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Catalog invariants surface as validation errors: they are always something the person
 * editing the product can fix.
 */
final class CatalogRuleViolation
{
    public static function simpleProductNeedsOneVariant(): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('A product without options has exactly one variant.')]);
    }

    public static function variantNeedsEveryOption(string $sku): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('Variant :sku must choose exactly one value for every option.', ['sku' => $sku])]);
    }

    public static function unknownOptionValue(string $sku, string $option, string $value): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('Variant :sku uses :value, which is not a value of :option.', compact('sku', 'option', 'value'))]);
    }

    public static function duplicateCombination(string $sku): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('Variant :sku repeats another variant\'s option combination.', ['sku' => $sku])]);
    }

    public static function skuTaken(string $sku): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('SKU :sku is already in use.', ['sku' => $sku])]);
    }

    public static function compareAtBelowPrice(string $sku): ValidationException
    {
        return ValidationException::withMessages(['variants' => __('Compare-at price of :sku must be above its price.', ['sku' => $sku])]);
    }

    public static function categoryCycle(): ValidationException
    {
        return ValidationException::withMessages(['parent' => __('A category cannot be moved under itself or its own descendant.')]);
    }

    public static function unknown(string $field, string $ulid): ValidationException
    {
        return ValidationException::withMessages([$field => __('Unknown :field :ulid.', ['field' => $field, 'ulid' => $ulid])]);
    }
}
