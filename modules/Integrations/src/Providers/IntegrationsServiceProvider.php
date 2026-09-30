<?php

declare(strict_types=1);

namespace Modules\Integrations\Providers;

use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Contracts\OutboxHandlers;
use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Integrations\Support\BufferedStorefrontCache;
use Modules\Integrations\Support\DatabaseOutbox;
use Modules\Integrations\Support\OutboxHandlerRegistry;
use Modules\Integrations\Support\RevalidateStorefront;
use Modules\Support\Providers\ModuleServiceProvider;

final class IntegrationsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Integrations';
    }

    protected function registerModule(): void
    {
        $this->app->bind(Outbox::class, DatabaseOutbox::class);
        $this->app->singleton(OutboxHandlerRegistry::class);
        $this->app->alias(OutboxHandlerRegistry::class, OutboxHandlers::class);
        // One buffer per request / queued job.
        $this->app->scoped(StorefrontCache::class, BufferedStorefrontCache::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(OutboxHandlers::class)->register(BufferedStorefrontCache::EVENT, RevalidateStorefront::class);
    }
}
