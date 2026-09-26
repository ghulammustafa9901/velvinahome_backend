<?php

declare(strict_types=1);

namespace Modules\Support\Queue;

use RuntimeException;

final class MissingTenantContext extends RuntimeException
{
    public static function forJob(object $job): self
    {
        return new self(sprintf(
            'Job [%s] requires tenant context but was processed without a tenant id. Refusing to run it in a default tenant.',
            $job::class,
        ));
    }
}
