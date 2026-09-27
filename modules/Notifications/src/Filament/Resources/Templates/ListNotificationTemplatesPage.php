<?php

declare(strict_types=1);

namespace Modules\Notifications\Filament\Resources\Templates;

use Filament\Resources\Pages\ListRecords;

final class ListNotificationTemplatesPage extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;
}
