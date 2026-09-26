<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Support\Contracts\Action;

/**
 * `tenant:suspend` / `tenant:activate`. A suspended tenant's hosts answer 503 and its admin
 * refuses sign-in; its data, schema and scheduled migrations are untouched.
 */
final class ChangeTenantStatus implements Action
{
    use AuthorizesPlatformActor;

    public function __construct(private readonly PlatformAudit $audit) {}

    public function handle(Tenant $tenant, TenantStatus $target, ?string $reason = null, ?PlatformUser $actor = null): Tenant
    {
        $this->authorizeTenantManagement($actor);

        $allowed = match ($target) {
            TenantStatus::Suspended => $tenant->status === TenantStatus::Active,
            TenantStatus::Active => $tenant->status === TenantStatus::Suspended,
            default => false,
        };

        if (! $allowed) {
            throw TenantLifecycleViolation::notSuspendable($tenant->slug, $tenant->status->value);
        }

        $from = $tenant->status;
        $tenant->forceFill(['status' => $target])->save();

        $this->audit->record('tenant.status_changed', $actor, $tenant->id, 'tenant', $tenant->id, [
            'from' => $from->value,
            'to' => $target->value,
            'reason' => $reason,
        ]);

        return $tenant;
    }
}
