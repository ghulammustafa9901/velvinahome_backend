<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use Modules\Platform\Enums\ProvisioningStep;
use RuntimeException;
use Throwable;

/**
 * A provisioning step failed. The tenant is left in `provisioning` with every earlier step
 * recorded as complete, so re-running `tenant:create` resumes here — never a silently broken
 * half-tenant (tenancy.md §5).
 */
final class ProvisioningFailed extends RuntimeException
{
    public static function atStep(string $slug, ProvisioningStep $step, Throwable $previous): self
    {
        return new self(
            sprintf('Provisioning tenant [%s] failed at step [%s]: %s', $slug, $step->value, $previous->getMessage()),
            previous: $previous,
        );
    }
}
