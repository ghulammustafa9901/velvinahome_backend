<?php

declare(strict_types=1);

namespace Modules\Orders\Contracts;

use DateTimeInterface;
use Modules\Orders\Data\SalesDay;

/**
 * Read-only order facts for Reporting. Days are calendar days in the given time zone,
 * by when the order was placed.
 */
interface SalesLedger
{
    /**
     * Days (Y-m-d) holding orders created or changed after the given moment.
     *
     * @return list<string>
     */
    public function daysChangedSince(DateTimeInterface $since, string $timezone): array;

    /**
     * Days (Y-m-d) holding any order, oldest first — for a full rebuild.
     *
     * @return list<string>
     */
    public function daysWithOrders(string $timezone): array;

    /**
     * @return list<SalesDay> one per currency sold in that day (empty when there were no orders)
     */
    public function day(string $date, string $timezone): array;
}
