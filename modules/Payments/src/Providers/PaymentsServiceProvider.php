<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Modules\Payments\Contracts\Payments;
use Modules\Payments\Contracts\RefundLedger;
use Modules\Payments\Seeders\DefaultPaymentMethodsSeeder;
use Modules\Payments\Support\DatabaseRefundLedger;
use Modules\Payments\Support\GatewayRegistry;
use Modules\Payments\Support\LedgerPayments;
use Modules\Payments\Support\PaymentsLaunchCheck;
use Modules\Platform\Contracts\OnboardingChecks;
use Modules\Support\Providers\ModuleServiceProvider;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Payments';
    }

    protected function registerModule(): void
    {
        $this->app->bind(Payments::class, LedgerPayments::class);
        $this->app->bind(RefundLedger::class, DatabaseRefundLedger::class);
        $this->app->singleton(GatewayRegistry::class);
    }

    protected function appendOnlyTables(): array
    {
        return ['payment_transactions', 'webhook_events'];
    }

    protected function tenantSeeders(): array
    {
        return [DefaultPaymentMethodsSeeder::class];
    }

    protected function bootModule(): void
    {
        $this->app->make(OnboardingChecks::class)->register(PaymentsLaunchCheck::class);
    }
}
