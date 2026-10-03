<?php

declare(strict_types=1);

namespace Modules\Settings\Contracts;

/**
 * The store's launch checklist. Modules register their items in the order they should appear;
 * the list is computed from the store's real state every time it is shown.
 */
interface OnboardingChecks
{
    /**
     * @param  class-string<OnboardingCheck>  $check
     */
    public function register(string $check): void;

    /**
     * Every item with its state. A check that errors counts as not done, with the reason,
     * never as done.
     *
     * @return list<array{label: string, done: bool, hint: string}>
     */
    public function items(): array;
}
