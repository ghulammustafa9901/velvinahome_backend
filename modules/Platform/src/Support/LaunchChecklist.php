<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Platform\Contracts\OnboardingCheck;
use Modules\Platform\Contracts\OnboardingChecks;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Throwable;

/**
 * A brand's launch checklist, computed from its real state every time it is shown: landlord
 * facts (domains, plan, theme) here, everything inside the brand from the modules' checks.
 * A check that errors counts as not done, with the reason, never as done.
 */
final class LaunchChecklist implements OnboardingChecks
{
    /** @var list<class-string<OnboardingCheck>> */
    private array $checks = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $check): void
    {
        $this->checks[] = $check;
    }

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
            [
                'label' => 'Theme chosen',
                'done' => trim((string) $tenant->getAttribute('theme_id')) !== '',
                'hint' => 'Set the storefront theme for this brand.',
            ],
        ];

        if ($tenant->status !== TenantStatus::Active) {
            return [...$items, ['label' => 'Brand is active', 'done' => false, 'hint' => 'Provisioning must finish (or the brand be re-activated) before the in-store checks can run.']];
        }

        /** @var list<array{label: string, done: bool, hint: string}> $inside */
        $inside = $tenant->run(fn (): array => array_map(function (string $class): array {
            $check = $this->container->make($class);

            try {
                $done = $check->passes();
                $hint = $check->hint();
            } catch (Throwable $e) {
                $done = false;
                $hint = 'Could not be checked: '.class_basename($e);
            }

            return ['label' => $check->label(), 'done' => $done, 'hint' => $hint];
        }, $this->checks));

        return [...$items, ...$inside];
    }
}
