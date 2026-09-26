<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Platform\Models\BrandSwitchToken;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\ImpersonationSession;
use Modules\Platform\Models\Tenant;
use Modules\Support\Contracts\Action;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Redeems a hand-off token on a tenant admin host and signs the right staff account in.
 *
 * Both kinds of hand-off — platform support access and the owner's brand switcher — are
 * verified against the landlord record: the token must exist (by hash), belong to THIS
 * tenant, be unexpired and unused. Redemption is atomic, so a token works exactly once.
 * Anything else is a plain 404: a forged or replayed token learns nothing.
 *
 * There is no cross-database session; the tenant session is created fresh here.
 */
final class ConsumeHandoff implements Action
{
    public const KIND_SUPPORT = 'support';

    public const KIND_BRAND = 'brand';

    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{staff_ulid: string, expires_at: \Illuminate\Support\Carbon|null, impersonation: string|null}
     */
    public function handle(Tenant $currentTenant, string $kind, string $token): array
    {
        $hash = hash('sha256', $token);

        $result = $kind === self::KIND_SUPPORT
            ? $this->consumeSupportToken($currentTenant, $hash)
            : $this->consumeBrandToken($currentTenant, $hash);

        if ($result === null || ! $this->staff->startSession($result['staff_ulid'])) {
            throw new NotFoundHttpException;
        }

        return $result;
    }

    /**
     * @return array{staff_ulid: string, expires_at: \Illuminate\Support\Carbon|null, impersonation: string|null}|null
     */
    private function consumeSupportToken(Tenant $tenant, string $hash): ?array
    {
        return $this->db->connection('landlord')->transaction(function () use ($tenant, $hash): ?array {
            /** @var ImpersonationSession|null $session */
            $session = ImpersonationSession::query()
                ->where('token_hash', $hash)
                ->where('tenant_id', $tenant->id)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            $session->forceFill(['consumed_at' => now()])->save();
            $session->loadMissing('platformUser');

            $this->staff->recordAccessEvent('platform_support_access', 'Platform support signed in to this admin', [
                'platform_user' => $session->platformUser->email,
                'reason' => $session->reason,
                'impersonation_session' => $session->ulid,
                'expires_at' => $session->expires_at->toIso8601String(),
            ]);

            return [
                'staff_ulid' => $session->target_staff_user_ulid,
                'expires_at' => $session->expires_at,
                'impersonation' => $session->ulid,
            ];
        });
    }

    /**
     * @return array{staff_ulid: string, expires_at: \Illuminate\Support\Carbon|null, impersonation: string|null}|null
     */
    private function consumeBrandToken(Tenant $tenant, string $hash): ?array
    {
        return $this->db->connection('landlord')->transaction(function () use ($tenant, $hash): ?array {
            /** @var BrandSwitchToken|null $token */
            $token = BrandSwitchToken::query()
                ->where('token_hash', $hash)
                ->where('to_tenant_id', $tenant->id)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return null;
            }

            $token->forceFill(['consumed_at' => now()])->save();

            $link = CompanyOwnerBrandLink::query()
                ->where('company_owner_identity_id', $token->company_owner_identity_id)
                ->where('tenant_id', $tenant->id)
                ->whereNull('revoked_at')
                ->first();

            if ($link === null) {
                return null;
            }

            $this->staff->recordAccessEvent('brand_switch', 'Company owner switched in from another brand', [
                'from_tenant' => $token->from_tenant_id,
            ]);

            return [
                'staff_ulid' => $link->tenant_staff_user_ulid,
                'expires_at' => null,
                'impersonation' => null,
            ];
        });
    }
}
