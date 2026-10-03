<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Settings\Contracts\OnboardingCheck;
use Modules\Settings\Contracts\OnboardingChecks;
use Modules\Settings\Contracts\TenantSettings;
use Throwable;

/**
 * The launch checklist: the store's own identity first (from settings), then every module's
 * registered check.
 */
final class LaunchChecklist implements OnboardingChecks
{
    /** @var list<class-string<OnboardingCheck>> */
    private array $checks = [];

    public function __construct(
        private readonly Container $container,
        private readonly TenantSettings $settings,
    ) {}

    public function register(string $check): void
    {
        if (! in_array($check, $this->checks, true)) {
            $this->checks[] = $check;
        }
    }

    public function items(): array
    {
        $items = [
            [
                'label' => 'Storefront URL set',
                'done' => $this->settings->storefrontUrl() !== null,
                'hint' => 'Enter the shop\'s public address under Settings → Store; emails link to it.',
            ],
            [
                'label' => 'Logo uploaded',
                'done' => $this->settings->string('store.logo') !== '',
                'hint' => 'Upload the store logo under Settings → Store.',
            ],
        ];

        foreach ($this->checks as $class) {
            $check = $this->container->make($class);

            try {
                $done = $check->passes();
                $hint = $check->hint();
            } catch (Throwable $e) {
                $done = false;
                $hint = 'Could not be checked: '.class_basename($e);
            }

            $items[] = ['label' => $check->label(), 'done' => $done, 'hint' => $hint];
        }

        return $items;
    }
}
