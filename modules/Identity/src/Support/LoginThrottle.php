<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Cache\RateLimiter;

/**
 * Per-account and per-IP sign-in throttling (spec §6.2, §8). Keys always include the tenant,
 * so failures on one brand never lock an account out of another, and one brand's attacker
 * traffic cannot exhaust another's budget.
 */
final class LoginThrottle
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function isLockedOut(string $population, string $email, ?string $ip): bool
    {
        return $this->limiter->tooManyAttempts($this->accountKey($population, $email), $this->maxAttempts())
            || ($ip !== null && $this->limiter->tooManyAttempts($this->ipKey($population, $ip), $this->maxAttempts() * 4));
    }

    public function recordFailure(string $population, string $email, ?string $ip): void
    {
        $this->limiter->hit($this->accountKey($population, $email), $this->lockoutSeconds());

        if ($ip !== null) {
            $this->limiter->hit($this->ipKey($population, $ip), $this->lockoutSeconds());
        }
    }

    public function clear(string $population, string $email): void
    {
        $this->limiter->clear($this->accountKey($population, $email));
    }

    public function secondsUntilAvailable(string $population, string $email): int
    {
        return $this->limiter->availableIn($this->accountKey($population, $email));
    }

    private function accountKey(string $population, string $email): string
    {
        return sprintf('login:%s:%s:account:%s', $this->tenantKey(), $population, sha1(strtolower($email)));
    }

    private function ipKey(string $population, string $ip): string
    {
        return sprintf('login:%s:%s:ip:%s', $this->tenantKey(), $population, $ip);
    }

    private function tenantKey(): string
    {
        return tenancy()->initialized ? (string) tenant()?->getTenantKey() : 'central';
    }

    private function maxAttempts(): int
    {
        return (int) config('platform.staff.max_login_attempts', 5);
    }

    private function lockoutSeconds(): int
    {
        return (int) config('platform.staff.lockout_seconds', 900);
    }
}
