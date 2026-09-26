<?php

declare(strict_types=1);

namespace Modules\Support\Queue;

/**
 * For jobs that only make sense inside a tenant. Adds middleware that fails the job loudly
 * when it arrives without tenant context — it must never run against a default or a
 * leftover tenant (spec §4.4).
 */
trait TenantAware
{
    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureTenantContext];
    }
}
