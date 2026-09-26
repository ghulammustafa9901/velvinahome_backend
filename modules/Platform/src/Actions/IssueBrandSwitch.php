<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\BrandSwitchToken;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\Tenant;
use Modules\Support\Contracts\Action;

/**
 * The brand switcher (spec §4.1a): a company owner, signed in to one brand's admin, moves to
 * another brand of the same company without signing in again.
 *
 * Both ends are checked in the landlord: the current staff account must be the owner's
 * linked account in the current brand, and the target brand must be linked to the same
 * owner identity. The result is a 60-second, single-use token redeemed on the target
 * brand's own admin host — no data and no session cross between the two databases.
 */
final class IssueBrandSwitch implements Action
{
    public function handle(Tenant $currentTenant, string $currentStaffUlid, Tenant $targetTenant): string
    {
        $currentLink = CompanyOwnerBrandLink::query()
            ->where('tenant_id', $currentTenant->id)
            ->where('tenant_staff_user_ulid', $currentStaffUlid)
            ->whereNull('revoked_at')
            ->first();

        $targetLink = $currentLink === null ? null : CompanyOwnerBrandLink::query()
            ->where('company_owner_identity_id', $currentLink->company_owner_identity_id)
            ->where('tenant_id', $targetTenant->id)
            ->whereNull('revoked_at')
            ->first();

        if ($targetLink === null || $targetTenant->status !== TenantStatus::Active || $targetTenant->company_id !== $currentTenant->company_id) {
            throw new AuthorizationException('You do not have owner access to that brand.');
        }

        $token = bin2hex(random_bytes(32));

        BrandSwitchToken::query()->create([
            'token_hash' => hash('sha256', $token),
            'company_owner_identity_id' => $currentLink?->company_owner_identity_id,
            'from_tenant_id' => $currentTenant->id,
            'to_tenant_id' => $targetTenant->id,
            'expires_at' => now()->addSeconds((int) config('platform.brand_switch.ttl_seconds')),
        ]);

        return sprintf('%s://%s/_handoff/brand/%s', request()->getScheme(), $targetTenant->primaryHost(DomainKind::Admin), $token);
    }
}
