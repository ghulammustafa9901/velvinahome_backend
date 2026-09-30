<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Inventory\Actions\ApplyStockCount;
use Modules\Inventory\Contracts\StockImport;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Models\Location;

final class ActionStockImport implements StockImport
{
    public function __construct(private readonly ApplyStockCount $count) {}

    public function count(array $quantities, string $note, ?string $locationUlid = null): int
    {
        if ($quantities === []) {
            return 0;
        }

        $locationUlid ??= Location::query()->where('is_default', true)->value('ulid');

        $lines = [];
        foreach ($quantities as $variantUlid => $quantity) {
            $lines[] = ['variantUlid' => (string) $variantUlid, 'quantity' => max(0, $quantity)];
        }

        return $this->count->handle(StockDocumentData::from([
            'locationUlid' => (string) $locationUlid,
            'lines' => $lines,
            'notes' => mb_substr($note, 0, 1000),
        ]))['differences'];
    }
}
