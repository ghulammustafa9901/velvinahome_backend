<?php

declare(strict_types=1);

namespace Modules\Platform\Data;

use Modules\Identity\Data\OwnerAccountData;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\ProvisioningStep;
use Modules\Platform\Models\Tenant;

/**
 * The outcome of one `tenant:create` run: which steps ran now, which were already done, the
 * owner credentials (once), and the go-live checklist.
 */
final class ProvisioningResult
{
    /** @var list<ProvisioningStep> */
    public array $completedNow = [];

    /** @var list<ProvisioningStep> */
    public array $alreadyComplete = [];

    public ?OwnerAccountData $owner = null;

    public function __construct(public readonly Tenant $tenant) {}

    /**
     * @return list<string>
     */
    public function goLiveChecklist(): array
    {
        $tenant = $this->tenant->fresh(['domains']) ?? $this->tenant;

        return [
            sprintf('Point DNS for %s (storefront) at the storefront deployment', $tenant->primaryHost(DomainKind::Storefront)),
            sprintf('Point DNS for %s (admin) and %s (API) at the API deployment', $tenant->primaryHost(DomainKind::Admin), $tenant->primaryHost(DomainKind::Api)),
            'Owner signs in to the admin and enrols two-factor authentication (mandatory)',
            'Set store information, currency and timezone in Settings',
            'Configure payment methods with the brand\'s own merchant credentials',
            'Configure shipping zones and rates',
            'Import or create the catalog, then verify stock levels',
            'Place a test COD order end to end and cancel it',
            'Confirm backups for this tenant appear in the platform panel',
            'Run the isolation suite against staging (docs/runbooks/verify-isolation.md)',
        ];
    }
}
