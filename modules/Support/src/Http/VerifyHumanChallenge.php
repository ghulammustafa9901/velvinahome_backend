<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cloudflare Turnstile on sign-in, registration, password reset requests and order placement
 * (spec §8). The storefront passes the widget's token as `X-Turnstile-Token`; it is checked
 * with Cloudflare once (tokens are single-use).
 *
 *  - Off until TURNSTILE_SECRET_KEY is set (local development, tests, brands before launch).
 *  - A passed check covers the same client's next two protected calls for two minutes, so
 *    "register, then sign in" needs one challenge. Throttles still apply to every call.
 *  - An invalid token is refused. If Cloudflare cannot be reached the request is let through
 *    and the outage reported: a COD checkout must not stop because a third party is down.
 */
final class VerifyHumanChallenge
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const FOLLOW_UPS = 2;

    private const PASS_SECONDS = 120;

    public function __construct(private readonly Http $http, private readonly RateLimiter $passes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('platform.turnstile.secret_key');

        if ($secret === '') {
            return $next($request);
        }

        $token = trim((string) $request->headers->get('X-Turnstile-Token', ''));
        $pass = $this->passKey($request);

        if ($token === '') {
            if ($this->usePass($pass)) {
                return $next($request);
            }

            $this->refuse();
        }

        if (! $this->verify($secret, $token, (string) $request->ip())) {
            $this->refuse();
        }

        // One hit marks the pass; each follow-up adds one (see usePass).
        $this->passes->clear($pass);
        $this->passes->hit($pass, self::PASS_SECONDS);

        return $next($request);
    }

    private function verify(string $secret, string $token, string $ip): bool
    {
        if (mb_strlen($token) > 2048) {
            return false;
        }

        try {
            $response = $this->http->asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable $e) {
            report($e);

            return true;
        }

        if ($response->serverError()) {
            report(new RuntimeException('Turnstile verification unavailable (HTTP '.$response->status().').'));

            return true;
        }

        return $response->json('success') === true;
    }

    private function usePass(string $key): bool
    {
        $hits = $this->passes->attempts($key);

        if ($hits < 1 || $hits > self::FOLLOW_UPS) {
            return false;
        }

        $this->passes->hit($key, self::PASS_SECONDS);

        return true;
    }

    private function passKey(Request $request): string
    {
        return 'turnstile-pass:'.hash('sha256', (string) $request->ip());
    }

    /**
     * @return never
     */
    private function refuse(): void
    {
        throw ValidationException::withMessages([
            'turnstile' => 'Please complete the security check and try again.',
        ]);
    }
}
