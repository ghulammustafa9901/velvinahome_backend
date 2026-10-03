<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;
use Typesense\Client;

/**
 * Deep health check for uptime monitoring (spec §9). Anyone gets the overall status; the
 * per-component detail needs the HEALTH_CHECK_TOKEN, because it describes the infrastructure.
 * Answers 503 when something is down, so a monitor alerts.
 */
final class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => DB::connection()->select('select 1 as ok')[0]->ok === 1),
            'redis' => $this->check(fn (): bool => (bool) Redis::connection()->ping()),
            'search' => $this->check(fn (): bool => (bool) (app(Client::class)->health->retrieve()['ok'] ?? false)),
            'queue_backlog' => $this->check(fn (): bool => Queue::size('default') < (int) config('store.monitoring.queue_backlog_alert', 1000)),
            'failed_jobs_last_hour' => $this->check(fn (): bool => DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', now()->subHour())->count() === 0),
        ];

        $healthy = ! in_array(false, $checks, true);
        $token = (string) config('store.monitoring.health_token');
        $authorised = $token !== '' && hash_equals($token, (string) $request->header('X-Health-Token', ''));

        return response()->json(
            $authorised ? ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks, 'release' => config('store.release')] : ['status' => $healthy ? 'ok' : 'degraded'],
            $healthy ? 200 : 503,
        )->header('Cache-Control', 'no-store');
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
