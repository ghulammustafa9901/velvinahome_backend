<?php

declare(strict_types=1);

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Platform\Contracts\TenantDirectory;
use Modules\Reporting\Queries\ReportDiscrepancies;

/**
 * Proves the reports match the raw order and payment records. Exits non-zero on any
 * difference, so it can gate a release or run as a scheduled audit.
 */
final class ReportsVerifyCommand extends Command
{
    protected $signature = 'reports:verify
        {--tenant=* : Tenant ids (default: every active tenant)}
        {--from= : First day, Y-m-d (default: 30 days ago)}
        {--to= : Last day, Y-m-d (default: today)}';

    protected $description = 'Compare report aggregates with the order and payment records';

    public function handle(TenantDirectory $tenants): int
    {
        /** @var list<string> $only */
        $only = $this->option('tenant');
        $to = (string) ($this->option('to') ?: Carbon::now()->format('Y-m-d'));
        $from = (string) ($this->option('from') ?: Carbon::parse($to)->subDays(30)->format('Y-m-d'));

        if (! $this->isDate($from) || ! $this->isDate($to) || $from > $to) {
            $this->components->error('--from and --to must be Y-m-d dates, --from not after --to.');

            return self::INVALID;
        }

        $failed = false;

        foreach ($only === [] ? $tenants->activeTenantIds() : $only as $tenantId) {
            $problems = $tenants->runIn($tenantId, fn (): array => app(ReportDiscrepancies::class)->between($from, $to));

            if ($problems === []) {
                $this->components->twoColumnDetail($tenantId, "<fg=green>matches</> ({$from} … {$to})");

                continue;
            }

            $failed = true;
            $this->components->twoColumnDetail($tenantId, '<fg=red>'.count($problems).' differences</>');
            foreach (array_slice($problems, 0, 50) as $problem) {
                $this->line('  '.$problem);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && Carbon::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
    }
}
