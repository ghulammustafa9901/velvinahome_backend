<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Data\CompanyData;
use Modules\Platform\Models\Company;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Support\Contracts\Action;

/**
 * Creates the contracting company. A landlord record only — a company has no database of
 * its own; each of its brands does (spec §4.6).
 */
final class CreateCompany implements Action
{
    use AuthorizesPlatformActor;

    public function __construct(private readonly PlatformAudit $audit) {}

    public function handle(CompanyData $data, ?PlatformUser $actor = null): Company
    {
        $this->authorizeTenantManagement($actor);

        $company = Company::query()->firstOrCreate(
            ['slug' => $data->slug],
            [
                'legal_name' => $data->legalName,
                'display_name' => $data->displayName,
                'contact_name' => $data->contactName,
                'contact_email' => $data->contactEmail,
                'contact_phone' => $data->contactPhone,
                'country_code' => strtoupper($data->countryCode),
                'contract_status' => $data->contractStatus,
            ],
        );

        if ($company->wasRecentlyCreated) {
            $this->audit->record('company.created', $actor, subjectType: 'company', subjectId: $company->ulid);
        }

        return $company;
    }
}
