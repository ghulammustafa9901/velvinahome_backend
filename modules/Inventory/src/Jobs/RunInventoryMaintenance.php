<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Modules\Inventory\Actions\ExpireReservations;
use Modules\Inventory\Actions\ReconcileInventory;
use Modules\Inventory\Actions\SnapshotInventory;

/**
 * Scheduled inventory work (spec §6.11). A thin caller: each task is an Action.
 */
final class RunInventoryMaintenance implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const TASKS = ['expire-reservations', 'reconcile', 'snapshot'];

    public int $tries = 3;

    public function __construct(public readonly string $task)
    {
        if (! in_array($task, self::TASKS, true)) {
            throw new InvalidArgumentException("Unknown inventory task [{$task}].");
        }

        $this->onQueue($task === 'expire-reservations' ? 'default' : 'reports');
    }

    public function handle(): void
    {
        match ($this->task) {
            'expire-reservations' => app(ExpireReservations::class)->handle(),
            'reconcile' => app(ReconcileInventory::class)->handle(),
            'snapshot' => app(SnapshotInventory::class)->handle(),
            default => throw new InvalidArgumentException("Unknown inventory task [{$this->task}]."),
        };
    }
}
