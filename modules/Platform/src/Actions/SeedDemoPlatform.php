<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Data\CompanyData;
use Modules\Platform\Data\ProvisioningResult;
use Modules\Platform\Data\TenantData;
use Modules\Platform\Models\Plan;
use Modules\Support\Contracts\Action;

/**
 * Local demo landscape (spec §10): one company with a single brand and one company with two
 * brands — the multi-brand case that isolation most often gets wrong — all on *.localhost.
 * Idempotent: re-running resumes or skips.
 */
final class SeedDemoPlatform implements Action
{
    /**
     * @var list<array{company: string, slug: string, name: string, theme: string}>
     */
    private const BRANDS = [
        ['company' => 'demo-solo', 'slug' => 'alpha', 'name' => 'Alpha Store', 'theme' => 'default'],
        ['company' => 'demo-group', 'slug' => 'bravo', 'name' => 'Velvina Home', 'theme' => 'velvina-home'],
        ['company' => 'demo-group', 'slug' => 'charlie', 'name' => 'Charlie Active', 'theme' => 'default'],
    ];

    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly CreateTenant $createTenant,
    ) {}

    /**
     * @return list<ProvisioningResult>
     */
    public function handle(): array
    {
        Plan::query()->firstOrCreate(['key' => 'standard'], [
            'name' => 'Standard',
            'default_features' => ['wishlist' => true, 'reviews' => true, 'blog' => true, 'multi_location_inventory' => false],
        ]);

        $this->createCompany->handle(CompanyData::from([
            'legalName' => 'Demo Solo (Pvt) Ltd', 'displayName' => 'Demo Solo', 'slug' => 'demo-solo',
        ]));
        $this->createCompany->handle(CompanyData::from([
            'legalName' => 'Demo Group (Pvt) Ltd', 'displayName' => 'Demo Group', 'slug' => 'demo-group',
        ]));

        $results = [];

        foreach (self::BRANDS as $brand) {
            $host = $brand['slug'].'.localhost';

            $results[] = $this->createTenant->handle(TenantData::from([
                'company' => $brand['company'],
                'slug' => $brand['slug'],
                'name' => $brand['name'],
                'storefrontHost' => $host,
                'adminHost' => 'admin.'.$host,
                'apiHost' => 'api.'.$host,
                'ownerName' => $brand['company'] === 'demo-group' ? 'Group Owner' : 'Solo Owner',
                'ownerEmail' => $brand['company'] === 'demo-group' ? 'owner@demo-group.test' : 'owner@demo-solo.test',
                'plan' => 'standard',
                'themeId' => $brand['theme'],
            ]));
        }

        return $results;
    }
}
