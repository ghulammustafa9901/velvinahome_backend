<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Modules register their launch-checklist items here, in the order they should appear.
 */
interface OnboardingChecks
{
    /**
     * @param  class-string<OnboardingCheck>  $check
     */
    public function register(string $check): void;
}
