<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use Modules\Identity\Data\OwnerAccountData;

/**
 * What other modules may ask of tenant staff accounts. Every method runs against the
 * current tenant's database, so callers must be inside tenant context — which is exactly
 * how provisioning, impersonation and the brand switcher use it.
 */
interface StaffDirectory
{
    /**
     * Create the tenant's owner account if it does not exist, with the Owner role. Returns a
     * one-time temporary password only when the account was created now.
     */
    public function ensureOwner(string $name, string $email): OwnerAccountData;

    public function findUlidByEmail(string $email): ?string;

    /**
     * The oldest active account holding the Owner role — the default target of platform
     * support access.
     */
    public function ownerUlid(): ?string;

    /**
     * The ULID of the staff member signed in to the admin in this request, if any.
     */
    public function currentStaffUlid(): ?string;

    public function isActive(string $staffUlid): bool;

    /**
     * Sign the staff member into the tenant admin guard for this request's session. Used
     * only after a landlord-verified, single-use hand-off (impersonation, brand switch).
     */
    public function startSession(string $staffUlid): bool;

    /**
     * Mirror a platform-side event into the tenant's own activity log, so the brand can
     * see who accessed its admin and why (spec §4.9).
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordAccessEvent(string $event, string $description, array $properties): void;
}
