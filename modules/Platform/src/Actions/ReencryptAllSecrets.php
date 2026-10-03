<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Contracts\TenantDirectory;
use Modules\Platform\Models\Tenant;
use Modules\Support\Contracts\Action;
use Modules\Support\Contracts\ReencryptsStoredSecrets;
use Modules\Support\Security\EncryptedColumn;
use Throwable;

/**
 * `secrets:reencrypt`, step 2 of an APP_KEY rotation (docs/runbooks/rotate-secrets.md): the
 * landlord (Support's central scope), then every tenant that still migrates (active,
 * suspended, provisioning). A tenant that fails is reported and the rest continue; re-running
 * is safe.
 */
final class ReencryptAllSecrets implements Action
{
    public function __construct(
        private readonly ReencryptsStoredSecrets $reencrypt,
        private readonly TenantDirectory $tenants,
    ) {}

    /**
     * Keyed by 'landlord' or the tenant slug: per-column counts, or the error.
     *
     * @return array<string, array<string, array{reencrypted: int, current: int, unreadable: int}>|string>
     */
    public function handle(): array
    {
        $report = ['landlord' => $this->reencrypt->handle(EncryptedColumn::CENTRAL, 'landlord')];

        $tenants = Tenant::listOf(Tenant::query()->orderBy('slug'));

        foreach ($tenants as $tenant) {
            if (! $tenant->status->isMigratable()) {
                continue;
            }

            try {
                $report[$tenant->slug] = $this->tenants->runIn($tenant->id, fn (): array => $this->reencrypt->handle(EncryptedColumn::TENANT, 'tenant'));
            } catch (Throwable $exception) {
                report($exception);
                $report[$tenant->slug] = 'failed: '.$exception->getMessage();
            }
        }

        return $report;
    }
}
