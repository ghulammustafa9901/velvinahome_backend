<?php

declare(strict_types=1);

namespace Modules\Identity\Listeners;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\LoginAudit;
use Modules\Identity\Models\StaffUser;
use Modules\Identity\Support\LoginThrottle;

/**
 * Staff sign-in through the admin panel: lockout before the attempt, an audit row for every
 * outcome, throttle bookkeeping after it. Wired to the framework's auth events so the
 * Filament login page needs no custom code to be held to the rules.
 */
final class AuditStaffAuthentication
{
    private const GUARD = 'staff';

    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly Request $request,
    ) {}

    public function handleAttempting(Attempting $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $email = (string) ($event->credentials['email'] ?? '');

        if ($this->throttle->isLockedOut('staff', $email, $this->request->ip())) {
            $this->audit($email, null, false, 'locked_out');

            throw ValidationException::withMessages([
                'data.email' => __('Too many sign-in attempts. Try again in :seconds seconds.', [
                    'seconds' => $this->throttle->secondsUntilAvailable('staff', $email),
                ]),
            ]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $email = (string) ($event->credentials['email'] ?? '');
        $userId = $event->user instanceof StaffUser ? $event->user->id : null;

        $this->throttle->recordFailure('staff', $email, $this->request->ip());
        $this->audit($email, $userId, false, $userId === null ? 'unknown_account' : 'invalid_password');
    }

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== self::GUARD || ! $event->user instanceof StaffUser) {
            return;
        }

        $staff = $event->user;

        $this->throttle->clear('staff', $staff->email);
        $this->audit($staff->email, $staff->id, true, null);

        $staff->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    private function audit(string $email, ?int $userId, bool $successful, ?string $reason): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        LoginAudit::query()->create([
            'subject_type' => 'staff',
            'email_attempted' => mb_substr(strtolower($email), 0, 255),
            'user_id' => $userId,
            'successful' => $successful,
            'failure_reason' => $reason,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
        ]);
    }
}
