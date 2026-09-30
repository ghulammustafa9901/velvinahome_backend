<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Modules\Imports\Models\ImportRun;

/**
 * Counts and issues of a run, written to the run row every few items so staff can follow
 * progress. Only the first MAX_ISSUES issues are kept; the total is always counted.
 */
final class ImportLog
{
    public const MAX_ISSUES = 500;

    private int $sinceFlush = 0;

    public function __construct(private readonly ImportRun $run) {}

    public function step(string $step): void
    {
        $this->run->step = $step;
        $this->flush();
    }

    public function count(string $entity, string $outcome, int $by = 1): void
    {
        $counts = $this->run->counts;
        $counts[$entity][$outcome] = ($counts[$entity][$outcome] ?? 0) + $by;
        $this->run->counts = $counts;

        if (++$this->sinceFlush >= 25) {
            $this->flush();
        }
    }

    public function issue(string $level, string $entity, ?string $ref, string $message): void
    {
        $this->run->issue_count++;

        if (count($this->run->issues) < self::MAX_ISSUES) {
            $issues = $this->run->issues;
            $issues[] = ['level' => $level, 'entity' => $entity, 'ref' => $ref, 'message' => mb_substr($message, 0, 500)];
            $this->run->issues = $issues;
        }
    }

    public function flush(): void
    {
        $this->sinceFlush = 0;
        $this->run->save();
    }
}
