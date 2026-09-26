<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Companies\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Actions\CreateCompany;
use Modules\Platform\Data\CompanyData;
use Modules\Platform\Filament\Resources\Companies\CompanyResource;
use Modules\Platform\Models\PlatformUser;

/**
 * Thin: the form's data becomes a DTO and the same Action `company:create` uses.
 */
final class CreateCompanyPage extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth('platform')->user();

        return app(CreateCompany::class)->handle(CompanyData::validateAndCreate([
            'legalName' => $data['legal_name'] ?? null,
            'displayName' => $data['display_name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'contactName' => $data['contact_name'] ?? null,
            'contactEmail' => $data['contact_email'] ?? null,
            'contactPhone' => $data['contact_phone'] ?? null,
            'countryCode' => $data['country_code'] ?? 'PK',
            'contractStatus' => $data['contract_status'] ?? 'active',
        ]), $actor instanceof PlatformUser ? $actor : null);
    }
}
