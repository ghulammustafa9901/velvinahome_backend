<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * The ordered steps of `tenant:create`. Each completed step is recorded on the tenant, so a
 * re-run after a failure resumes at the first incomplete step instead of duplicating work
 * (spec §4.6: idempotent and safe to re-run).
 */
enum ProvisioningStep: string
{
    case Database = 'database';
    case Migrations = 'migrations';
    case Seed = 'seed';
    case SearchCollections = 'search_collections';
    case StoragePrefix = 'storage_prefix';
    case OwnerAccount = 'owner_account';
    case Activate = 'activate';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Create database and least-privilege roles',
            self::Migrations => 'Run tenant migrations and ledger lockdown',
            self::Seed => 'Seed roles, permissions and default settings',
            self::SearchCollections => 'Create search collections',
            self::StoragePrefix => 'Prepare the storage prefix',
            self::OwnerAccount => 'Create the owner admin account',
            self::Activate => 'Activate the tenant',
        };
    }
}
