<?php

declare(strict_types=1);

namespace Modules\Reporting\Actions;

use Illuminate\Support\Carbon;
use Modules\Reporting\Models\ReportCursor;
use Modules\Reporting\Support\ReportFacts;
use Modules\Support\Contracts\Action;

/**
 * Bring the reports up to date: rebuild every day whose orders or refunds changed since the
 * last run (everything on the first run).
 *
 * The watermark is taken before reading, and each run looks back an extra few minutes, so a
 * transaction that committed late with an earlier timestamp is still picked up next time.
 */
final class RefreshReports implements Action
{
    public const CURSOR = 'sales';

    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly ReportFacts $facts,
        private readonly RebuildReportDays $rebuild,
    ) {}

    /**
     * @return list<string> the days rebuilt
     */
    public function handle(bool $full = false): array
    {
        $startedAt = Carbon::now();
        $cursor = ReportCursor::query()->find(self::CURSOR);

        $days = $full || $cursor === null
            ? $this->facts->allDays()
            : $this->facts->changedSince($cursor->value->copy()->subMinutes(self::OVERLAP_MINUTES));

        $this->rebuild->handle($days);

        ReportCursor::query()->updateOrCreate(['key' => self::CURSOR], ['value' => $startedAt]);

        return $days;
    }
}
