<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use DateTimeInterface;

/**
 * Read-only refund facts for Reporting. A refund counts on the calendar day (in the given
 * time zone) it was recorded, not the day the order was placed.
 */
interface RefundLedger
{
    /**
     * Days (Y-m-d) with refunds recorded after the given moment.
     *
     * @return list<string>
     */
    public function daysChangedSince(DateTimeInterface $since, string $timezone): array;

    /**
     * Days (Y-m-d) with any refund, oldest first.
     *
     * @return list<string>
     */
    public function daysWithRefunds(string $timezone): array;

    /**
     * @return array<string, array{amount: int, count: int}> refunded minor units and refund count, per currency
     */
    public function day(string $date, string $timezone): array;
}
