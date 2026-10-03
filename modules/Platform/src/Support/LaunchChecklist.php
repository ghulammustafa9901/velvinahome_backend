<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Modules\Settings\Contracts\OnboardingChecks;

/**
 * A brand's launch checklist on the platform panel: landlord facts (domains, plan) here, the
 * store's own checklist (Settings) from inside the brand. Removed with the platform panel in
 * the single-store conversion.
 */
final class LaunchChecklist
{
    /**
     * @return list<array{label: string, done: bool, hint: string}>
     */
    public function for(Tenant $tenant): array
    {
        $tenant->loadMissing('domains');
        $kinds = $tenant->domains->map(fn ($domain) => $domain->kind)->all();

        $items = [
            [
                'label' => 'Storefront, admin and API addresses',
                'done' => in_array(DomainKind::Storefront, $kinds, true) && in_array(DomainKind::Admin, $kinds, true) && in_array(DomainKind::Api, $kinds, true),
                'hint' => 'Add the missing hostnames and point their DNS at the platform.',
            ],
            [
                'label' => 'Plan assigned',
                'done' => $tenant->getAttribute('plan_id') !== null,
                'hint' => 'Choose the brand\'s plan; it decides the default features.',
            ],
        ];

        if ($tenant->status !== TenantStatus::Active) {
            return [...$items, ['label' => 'Brand is active', 'done' => false, 'hint' => 'Provisioning must finish (or the brand be re-activated) before the in-store checks can run.']];
        }

        /** @var list<array{label: string, done: bool, hint: string}> $inside */
        $inside = $tenant->run(fn (): array => app(OnboardingChecks::class)->items());

        return [...$items, ...$inside];
    }
}
