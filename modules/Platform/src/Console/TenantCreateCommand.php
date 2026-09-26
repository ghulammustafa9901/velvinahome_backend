<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Data\ProvisioningResult;
use Modules\Platform\Data\TenantData;
use Modules\Platform\Enums\ProvisioningStep;
use Modules\Platform\Exceptions\ProvisioningFailed;

final class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {company : Slug of the owning company}
        {slug : Brand slug, e.g. velvina-home (names the database; cannot change later)}
        {name : Brand name}
        {--storefront= : Storefront host, e.g. velvinahome.com}
        {--admin= : Admin host (default admin.<storefront>)}
        {--api= : API host (default api.<storefront>)}
        {--owner-name= : Owner admin name}
        {--owner-email= : Owner admin email}
        {--plan= : Plan key}
        {--currency=PKR}
        {--timezone=Asia/Karachi}
        {--theme=default}';

    protected $description = 'Provision a brand: database, roles, schema, defaults, storage, owner account (idempotent — re-run to resume)';

    public function handle(CreateTenant $createTenant): int
    {
        $storefront = strtolower((string) ($this->option('storefront') ?: $this->argument('slug').'.localhost'));

        $data = TenantData::validateAndCreate([
            'company' => (string) $this->argument('company'),
            'slug' => (string) $this->argument('slug'),
            'name' => (string) $this->argument('name'),
            'storefrontHost' => $storefront,
            'adminHost' => (string) ($this->option('admin') ?: 'admin.'.$storefront),
            'apiHost' => (string) ($this->option('api') ?: 'api.'.$storefront),
            'ownerName' => (string) ($this->option('owner-name') ?: 'Store Owner'),
            'ownerEmail' => (string) ($this->option('owner-email') ?: 'owner@'.$storefront),
            'plan' => $this->option('plan') ?: null,
            'currency' => (string) $this->option('currency'),
            'timezone' => (string) $this->option('timezone'),
            'themeId' => (string) $this->option('theme'),
        ]);

        try {
            $result = $createTenant->handle($data);
        } catch (ProvisioningFailed $exception) {
            $this->components->error($exception->getMessage());
            $this->line('  Fix the cause and run the same command again: completed steps are skipped.');

            return self::FAILURE;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    private function report(ProvisioningResult $result): void
    {
        foreach (ProvisioningStep::cases() as $step) {
            $state = in_array($step, $result->alreadyComplete, true) ? 'already done' : 'done';
            $this->components->twoColumnDetail($step->label(), $state);
        }

        $this->newLine();
        $this->components->info(sprintf('Tenant [%s] is %s (id %s).', $result->tenant->slug, $result->tenant->status->value, $result->tenant->id));

        if ($result->owner?->temporaryPassword !== null) {
            $this->components->warn('Owner temporary password (shown once): '.$result->owner->temporaryPassword);
        }

        $this->components->bulletList($result->goLiveChecklist());
    }
}
