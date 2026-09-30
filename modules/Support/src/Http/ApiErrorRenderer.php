<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error shape for every API failure (api-conventions §3):
 *
 *   { "error": { "code": "validation_failed", "message": "…", "details": {…}, "request_id": "…" } }
 *
 * Codes are stable and machine-readable. A 500 never carries a stack trace, path or SQL.
 * Domain exceptions opt into a status and code by class name (see CODES), and into `details`
 * with a public `details(): array` method, so modules need not depend on this renderer.
 */
final class ApiErrorRenderer
{
    /**
     * Domain exception short class name => [HTTP status, code].
     *
     * @var array<string, array{int, string}>
     */
    private const CODES = [
        'InsufficientStock' => [409, 'stock_unavailable'],
        'PriceChanged' => [409, 'price_changed'],
        'IdempotencyKeyReused' => [409, 'idempotency_key_reused'],
        'CouponUnavailable' => [409, 'coupon_unavailable'],
    ];

    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $message, $details] = $this->classify($exception);

        $response = response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => Context::get('request_id'),
            ], fn ($value): bool => $value !== null),
        ], $status);

        if ($exception instanceof HttpExceptionInterface) {
            // Rate-limit headers arrive as integers (Retry-After, X-RateLimit-*).
            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, is_array($value) ? array_map('strval', $value) : (string) $value);
            }
        }

        return $response;
    }

    /**
     * @return array{int, string, string, array<string, mixed>|null}
     */
    private function classify(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => [$exception->status, $exception->status === 429 ? 'rate_limited' : 'validation_failed', 'The given data was invalid.', $exception->errors()],
            $exception instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.', null],
            $exception instanceof AuthorizationException => [403, 'forbidden', 'This action is not allowed.', null],
            $exception instanceof ModelNotFoundException => [404, 'not_found', 'The requested resource was not found.', null],
            $exception instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests.', null],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                match ($exception->getStatusCode()) {
                    404 => 'not_found',
                    403 => 'forbidden',
                    405 => 'method_not_allowed',
                    503 => 'unavailable',
                    default => 'http_error',
                },
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                null,
            ],
            $exception instanceof DomainException && isset(self::CODES[class_basename($exception)]) => [
                self::CODES[class_basename($exception)][0],
                self::CODES[class_basename($exception)][1],
                $exception->getMessage(),
                // A domain exception may carry structured details, e.g. the new totals.
                method_exists($exception, 'details') ? $exception->details() : null,
            ],
            default => [500, 'server_error', config('app.debug') ? $exception->getMessage() : 'Something went wrong.', null],
        };
    }
}
