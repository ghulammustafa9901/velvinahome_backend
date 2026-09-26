<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\CreateCompany;
use Modules\Platform\Data\CompanyData;

final class CompanyCreateCommand extends Command
{
    protected $signature = 'company:create
        {slug : URL-safe identifier, e.g. velvina-group}
        {name : Display name}
        {--legal-name= : Legal name (defaults to the display name)}
        {--email= : Contact email}
        {--country=PK : ISO country code}';

    protected $description = 'Create a company (the contracting party) in the landlord database';

    public function handle(CreateCompany $createCompany): int
    {
        $data = CompanyData::validateAndCreate([
            'slug' => (string) $this->argument('slug'),
            'displayName' => (string) $this->argument('name'),
            'legalName' => (string) ($this->option('legal-name') ?: $this->argument('name')),
            'contactEmail' => $this->option('email') ?: null,
            'countryCode' => (string) $this->option('country'),
        ]);

        $company = $createCompany->handle($data);

        $this->components->info(sprintf('Company [%s] ready (%s).', $company->slug, $company->ulid));

        return self::SUCCESS;
    }
}
