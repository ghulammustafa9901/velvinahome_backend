<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Inventory\Actions\ReserveStock;
use Modules\Inventory\Actions\RestockReservation;
use Modules\Inventory\Actions\SettleReservation;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Inventory\Models\StockReservation;

final class ActionStockReservations implements StockReservations
{
    public function __construct(
        private readonly ReserveStock $reserve,
        private readonly SettleReservation $settle,
        private readonly RestockReservation $restock,
    ) {}

    public function reserve(string $variantUlid, int $quantity, string $referenceType, string $referenceUlid, int $ttlMinutes): string
    {
        return $this->reserve->handle($variantUlid, $quantity, $referenceType, $referenceUlid, $ttlMinutes)->ulid;
    }

    public function commit(string $reservationUlid, string $orderUlid): void
    {
        $this->settle->handle($reservationUlid, 'commit', $orderUlid);
    }

    public function release(string $reservationUlid): void
    {
        $this->settle->handle($reservationUlid, 'release');
    }

    public function restock(string $reservationUlid, int $quantity, string $reason, string $orderUlid): void
    {
        $this->restock->handle($reservationUlid, $quantity, $reason, $orderUlid);
    }

    public function status(string $reservationUlid): ?string
    {
        /** @var 'active'|'converted'|'released'|'expired'|null */
        return StockReservation::query()->where('ulid', $reservationUlid)->value('status');
    }
}
