<?php

declare(strict_types=1);

namespace Modules\Identity\Filament\Resources\Staff\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Identity\Filament\Resources\Staff\StaffResource;

final class ListStaffPage extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
