<?php

declare(strict_types=1);

namespace Modules\Payments\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Modules\Payments\Contracts\RefundLedger;

/**
 * Refunds read from the append-only transaction ledger.
 */
final class DatabaseRefundLedger implements RefundLedger
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function daysChangedSince(DateTimeInterface $since, string $timezone): array
    {
        return $this->days($this->refunds()->where('created_at', '>', $since), $timezone);
    }

    public function daysWithRefunds(string $timezone): array
    {
        return $this->days($this->refunds(), $timezone);
    }

    public function day(string $date, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($timezone));

        if ($start === null || $start->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a calendar date: {$date}");
        }

        $rows = $this->refunds()
            ->where('created_at', '>=', $start->utc())
            ->where('created_at', '<', $start->addDay()->utc())
            ->groupBy('amount_currency')
            ->selectRaw('amount_currency as currency, sum(amount_amount) as amount, count(*) as refunds')
            ->get();

        $day = [];
        foreach ($rows as $row) {
            $day[(string) $row->currency] = ['amount' => (int) $row->amount, 'count' => (int) $row->refunds];
        }

        return $day;
    }

    private function refunds(): Builder
    {
        return $this->db->connection()->table('payment_transactions')->where('type', 'refund');
    }

    /**
     * @return list<string>
     */
    private function days(Builder $refunds, string $timezone): array
    {
        new DateTimeZone($timezone);

        return array_values($refunds
            ->selectRaw('distinct (created_at at time zone ?)::date as day', [$timezone])
            ->orderBy('day')
            ->pluck('day')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))
            ->all());
    }
}
