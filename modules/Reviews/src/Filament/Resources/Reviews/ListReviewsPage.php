<?php

declare(strict_types=1);

namespace Modules\Reviews\Filament\Resources\Reviews;

use Filament\Resources\Pages\ListRecords;

final class ListReviewsPage extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}
