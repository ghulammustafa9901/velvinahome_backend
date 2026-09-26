<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Tenants\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Data\TenantData;
use Modules\Platform\Exceptions\ProvisioningFailed;
use Modules\Platform\Filament\Resources\Tenants\TenantResource;
use Modules\Platform\Models\PlatformUser;

/**
 * Provisioning from the panel is the same CreateTenant Action as `tenant:create`, including
 * its resumability: submitting the same slug again after a failure resumes it.
 */
final class CreateTenantPage extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth('platform')->user();

        try {
            $result = app(CreateTenant::class)->handle(TenantData::validateAndCreate([
                'company' => $data['company'] ?? null,
                'name' => $data['name'] ?? null,
                'slug' => $data['slug'] ?? null,
                'storefrontHost' => $data['storefront_host'] ?? null,
                'adminHost' => $data['admin_host'] ?? null,
                'apiHost' => $data['api_host'] ?? null,
                'ownerName' => $data['owner_name'] ?? null,
                'ownerEmail' => $data['owner_email'] ?? null,
                'plan' => $data['plan'] ?? null,
                'currency' => $data['currency'] ?? 'PKR',
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                'themeId' => $data['theme_id'] ?? 'default',
            ]), $actor instanceof PlatformUser ? $actor : null);
        } catch (ProvisioningFailed $exception) {
            Notification::make()->title('Provisioning stopped')->body($exception->getMessage().' Submit again to resume.')
                ->danger()->persistent()->send();

            throw new Halt;
        }

        if ($result->owner?->temporaryPassword !== null) {
            Notification::make()
                ->title('Owner account created')
                ->body(sprintf('%s — temporary password (shown once): %s', $result->owner->email, $result->owner->temporaryPassword))
                ->persistent()
                ->warning()
                ->send();
        }

        return $result->tenant;
    }
}
