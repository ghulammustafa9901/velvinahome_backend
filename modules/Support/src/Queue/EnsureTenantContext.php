<?php

declare(strict_types=1);

namespace Modules\Support\Queue;

use Closure;

final class EnsureTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! tenancy()->initialized) {
            throw MissingTenantContext::forJob($job);
        }

        return $next($job);
    }
}
