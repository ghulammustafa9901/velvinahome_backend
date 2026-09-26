<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Modules\Platform\Models\PlatformLoginAudit;
use Modules\Platform\Models\PlatformUser;

/**
 * Every platform panel sign-in attempt lands in the append-only `platform_login_audits`
 * (spec §6.2, §9). Throttling of the form itself is Filament's login rate limit.
 */
final class AuditPlatformAuthentication
{
    private const GUARD = 'platform';

    public function __construct(private readonly Request $request) {}

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $user = $event->user instanceof PlatformUser ? $event->user : null;

        $this->audit((string) ($event->credentials['email'] ?? ''), $user?->id, false, $user === null ? 'unknown_account' : 'invalid_password');
    }

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== self::GUARD || ! $event->user instanceof PlatformUser) {
            return;
        }

        $this->audit($event->user->email, $event->user->id, true, null);
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    private function audit(string $email, ?int $userId, bool $successful, ?string $reason): void
    {
        PlatformLoginAudit::query()->create([
            'email_attempted' => mb_substr(strtolower($email), 0, 255),
            'platform_user_id' => $userId,
            'successful' => $successful,
            'failure_reason' => $reason,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
        ]);
    }
}
