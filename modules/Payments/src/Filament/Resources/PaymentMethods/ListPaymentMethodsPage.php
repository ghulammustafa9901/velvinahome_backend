<?php

declare(strict_types=1);

namespace Modules\Payments\Filament\Resources\PaymentMethods;

use Filament\Resources\Pages\ListRecords;

final class ListPaymentMethodsPage extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;
}
