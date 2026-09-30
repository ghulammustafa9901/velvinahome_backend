<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

/**
 * Category names for other modules' admin screens (e.g. choosing which categories a
 * promotion covers).
 */
interface CategoryDirectory
{
    /**
     * @return array<string, string> category ULID => name, in tree order ("Home › Bedding")
     */
    public function options(): array;
}
