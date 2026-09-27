<?php

declare(strict_types=1);

namespace Modules\Integrations\Providers;

use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Contracts\OutboxHandlers;
use Modules\Integrations\Support\DatabaseOutbox;
use Modules\Integrations\Support\OutboxHandlerRegistry;
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
    }
}
