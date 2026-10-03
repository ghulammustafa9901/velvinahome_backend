<?php

declare(strict_types=1);

namespace Modules\Support\Monitoring;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends unhandled exceptions to PostHog error tracking (owner decision 2026-10-01, in place of
 * Sentry from spec §9).
 *
 * What leaves the server is deliberately small: exception class, a scrubbed message, the
 * in-app stack frames (paths relative to the app), request id, release and the route path. Never request bodies, query strings, headers, cookies, emails or SQL bindings.
 * The same error is reported at most once a minute, and sending happens after the response.
 */
final class PostHogErrors
{
    private const MAX_FRAMES = 30;

    public function __construct(private readonly Cache $cache) {}

    public function enabled(): bool
    {
        return (string) config('platform.monitoring.posthog.key') !== '' && (string) config('platform.monitoring.posthog.host') !== '';
    }

    public function report(Throwable $e): void
    {
        if (! $this->enabled()) {
            return;
        }

        $payload = $this->payload($e);
        $fingerprint = 'monitoring:posthog:'.sha1($payload['properties']['$exception_fingerprint']);

        // One report per error per minute: a broken page under load is one alert, not thousands.
        if (! $this->cache->add($fingerprint, 1, 60)) {
            return;
        }

        $send = function () use ($payload): void {
            try {
                Http::timeout(3)->connectTimeout(2)->asJson()
                    ->post(rtrim((string) config('platform.monitoring.posthog.host'), '/').'/i/v0/e/', $payload);
            } catch (Throwable $sendError) {
                // Monitoring must never become the outage: log locally and move on.
                Log::warning('Could not send an error to PostHog', ['exception' => $sendError::class]);
            }
        };

        app()->runningInConsole() ? $send() : app()->terminating($send);
    }

    /**
     * @return array{api_key: string, event: string, distinct_id: string, properties: array<string, mixed>}
     */
    public function payload(Throwable $e): array
    {
        $request = app()->runningInConsole() ? null : request();
        $frames = $this->frames($e);

        return [
            'api_key' => (string) config('platform.monitoring.posthog.key'),
            'event' => '$exception',
            // Errors are not tied to people: one technical id for the service.
            'distinct_id' => 'platform-api',
            'properties' => [
                '$exception_list' => [[
                    'type' => $e::class,
                    'value' => $this->message($e),
                    'mechanism' => ['handled' => false, 'synthetic' => false],
                    'stacktrace' => ['type' => 'raw', 'frames' => $frames],
                ]],
                '$exception_level' => 'error',
                '$exception_fingerprint' => $e::class.'|'.($frames[0]['filename'] ?? '').':'.($frames[0]['lineno'] ?? 0),
                'service' => 'platform-api',
                'environment' => (string) config('app.env'),
                'release' => (string) config('platform.release'),
                'request_id' => Context::get('request_id'),
                'http_method' => $request?->method(),
                'route' => $request?->route()?->uri(),
                '$process_person_profile' => false,
            ],
        ];
    }

    /**
     * Messages can carry personal data (a validation value, an email, SQL with bindings).
     */
    private function message(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return 'Database error (SQLSTATE '.($e->errorInfo[0] ?? '?').')';
        }

        $message = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email]', $e->getMessage());
        $message = (string) preg_replace('/\b\d{6,}\b/', '[number]', $message);

        return mb_substr($message, 0, 300);
    }

    /**
     * @return list<array{filename: string, lineno: int, function: string, in_app: bool, platform: string}>
     */
    private function frames(Throwable $e): array
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '', 'class' => '']];

        foreach ($e->getTrace() as $frame) {
            $frames[] = $frame;
        }

        $out = [];
        foreach (array_slice($frames, 0, self::MAX_FRAMES) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            $relative = str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);

            $out[] = [
                'filename' => $relative,
                'lineno' => (int) ($frame['line'] ?? 0),
                'function' => trim(((string) ($frame['class'] ?? '')).'::'.((string) ($frame['function'] ?? '')), ':'),
                'in_app' => $relative !== '' && ! str_starts_with($relative, 'vendor/'),
                'platform' => 'custom',
            ];
        }

        return $out;
    }
}
