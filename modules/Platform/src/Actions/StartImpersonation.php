<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\ImpersonationSession;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Support\PlatformAudit;
use Modules\Support\Contracts\Action;

/**
 * Support access into a tenant admin (spec §4.9): who, when and why — always.
 *
 * Produces a single-use hand-off URL on the tenant's admin host. The session it opens is
 * time-limited, recorded in the landlord `impersonation_sessions`, and mirrored into the
 * tenant's own activity log when the hand-off is consumed.
 */
final class StartImpersonation implements Action
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * @return array{session: ImpersonationSession, url: string}
     */
    public function handle(Tenant $tenant, PlatformUser $actor, string $reason, ?string $ip = null, ?string $userAgent = null): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => __('Explain why support access is needed (at least :min characters).', ['min' => self::MIN_REASON_LENGTH])]);
        }

        if ($actor->status !== 'active') {
            throw new \Illuminate\Auth\Access\AuthorizationException('This platform account is disabled.');
        }

        if (! $tenant->status->isServable()) {
            throw TenantLifecycleViolation::notSuspendable($tenant->slug, $tenant->status->value);
        }

        /** @var string|null $target */
        $target = $tenant->run(fn (): ?string => $this->staff->ownerUlid());

        if ($target === null) {
            throw ValidationException::withMessages(['tenant' => __('This brand has no active owner account to access.')]);
        }

        $token = bin2hex(random_bytes(32));

        $session = ImpersonationSession::query()->create([
            'platform_user_id' => $actor->id,
            'tenant_id' => $tenant->id,
            'target_staff_user_ulid' => $target,
            'reason' => $reason,
            'token_hash' => hash('sha256', $token),
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('platform.impersonation.ttl_minutes')),
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        $this->audit->record('tenant.impersonation_started', $actor, $tenant->id, 'impersonation_session', $session->ulid, [
            'reason' => $reason,
            'target_staff_user_ulid' => $target,
        ]);

        return [
            'session' => $session,
            'url' => sprintf('%s://%s/_handoff/support/%s', request()->getScheme(), $tenant->primaryHost(DomainKind::Admin), $token),
        ];
    }
}
