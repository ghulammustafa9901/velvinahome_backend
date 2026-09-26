<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\Companies\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Resources\Companies\CompanyResource;

final class ListCompaniesPage extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
