<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use DomainException;

final class TenantLifecycleViolation extends DomainException
{
    public static function slugTaken(string $slug): self
    {
        return new self("A tenant with slug [{$slug}] already exists and is fully provisioned.");
    }

    public static function companyMismatch(string $slug): self
    {
        return new self("Tenant [{$slug}] is being provisioned under a different company; refusing to continue.");
    }

    public static function hostTaken(string $hostname): self
    {
        return new self("Hostname [{$hostname}] is already assigned to another tenant.");
    }

    public static function deletionNotConfirmed(string $slug): self
    {
        return new self("Deleting tenant [{$slug}] requires typing its slug as confirmation.");
    }

    public static function noFreshBackup(string $slug, int $maxAgeMinutes): self
    {
        return new self("Tenant [{$slug}] has no successful backup from the last {$maxAgeMinutes} minutes. Run `tenant:export` first.");
    }

    public static function notSuspendable(string $slug, string $status): self
    {
        return new self("Tenant [{$slug}] cannot change status from [{$status}].");
    }
}
