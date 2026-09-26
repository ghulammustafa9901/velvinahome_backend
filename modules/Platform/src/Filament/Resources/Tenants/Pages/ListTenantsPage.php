<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Tenants\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Resources\Tenants\TenantResource;

final class ListTenantsPage extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Provision brand')];
    }
}
