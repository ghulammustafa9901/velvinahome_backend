<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * One item of a brand's launch checklist (spec §13 phase 6, docs/tenant-onboarding.md),
 * owned by the module that knows the answer. `passes()` runs inside the brand's tenancy.
 */
interface OnboardingCheck
{
    public function label(): string;

    /**
     * What to do when it does not pass, in the brand admin's words.
     */
    public function hint(): string;

    public function passes(): bool;
}
