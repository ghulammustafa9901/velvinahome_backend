<?php

declare(strict_types=1);

namespace Modules\Settings\Contracts;

/**
 * One item of the store's launch checklist (docs/store-launch-checklist.md), owned by the module
 * that knows the answer.
 */
interface OnboardingCheck
{
    public function label(): string;

    /**
     * What to do when it does not pass, in the store admin's words.
     */
    public function hint(): string;

    public function passes(): bool;
}
