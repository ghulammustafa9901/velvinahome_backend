<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Catalog\Contracts\SearchIndex;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Actions\CreateCompany;
use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Actions\MigrateLandlord;
use Modules\Platform\Actions\MigrateTenants;
use Modules\Platform\Actions\SeedTenant;
use Modules\Platform\Data\CompanyData;
use Modules\Platform\Data\TenantData;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use RuntimeException;
use Throwable;

/**
 * The isolation fixture (plan 1.8): company X owns brand A; company Y owns brands B and C —
 * the two-brands-one-company case that isolation most often gets wrong.
 *
 * Provisioning a real PostgreSQL database per brand is the slow part, so fixture tenants are
 * created once and reused across runs; each run only migrates them forward. Tests write
 * uniquely named data, so reuse never makes one test depend on another.
 */
final class TestPlatform
{
    public const BRAND_A = 'fix-a';

    public const BRAND_B = 'fix-b';

    public const BRAND_C = 'fix-c';

    private static bool $prepared = false;

    /** @var array<string, Tenant> */
    private static array $brands = [];

    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }

        app(MigrateLandlord::class)->handle();
        self::dropTemporaryTenants();

        $createCompany = app(CreateCompany::class);
        $createCompany->handle(CompanyData::from(['legalName' => 'Fixture X', 'displayName' => 'Fixture X', 'slug' => 'fixture-x']));
        $createCompany->handle(CompanyData::from(['legalName' => 'Fixture Y', 'displayName' => 'Fixture Y', 'slug' => 'fixture-y']));

        foreach ([self::BRAND_A => 'fixture-x', self::BRAND_B => 'fixture-y', self::BRAND_C => 'fixture-y'] as $slug => $company) {
            $existing = Tenant::query()->where('slug', $slug)->first();

            if ($existing === null || $existing->status->value === 'provisioning') {
                app(CreateTenant::class)->handle(self::tenantData($slug, $company));
            }
        }

        $failed = array_filter(
            app(MigrateTenants::class)->handle([self::BRAND_A, self::BRAND_B, self::BRAND_C]),
            fn ($run): bool => $run->status !== 'succeeded',
        );

        if ($failed !== []) {
            throw new RuntimeException('Fixture tenant migration failed: '.array_values($failed)[0]->error);
        }

        // Fixture tenants outlive the code that provisioned them; bring their seeds and search
        // collections up to date the way a deploy would.
        foreach ([self::BRAND_A, self::BRAND_B, self::BRAND_C] as $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
            app(SeedTenant::class)->handle($tenant);
            // Rebuild (not just ensure) so a schema change in code reaches the fixtures.
            $tenant->run(fn () => app(SearchIndex::class)->reindex());
        }

        self::$prepared = true;
    }

    public static function brand(string $slug): Tenant
    {
        return self::$brands[$slug] ??= Tenant::query()->where('slug', $slug)->with(['databaseCredentials', 'domains'])->firstOrFail();
    }

    public static function apiHost(string $slug): string
    {
        return 'api.'.$slug.'.test';
    }

    public static function adminHost(string $slug): string
    {
        return 'admin.'.$slug.'.test';
    }

    public static function tenantData(string $slug, string $company): TenantData
    {
        return TenantData::from([
            'company' => $company,
            'slug' => $slug,
            'name' => 'Brand '.strtoupper($slug),
            'storefrontHost' => $slug.'.test',
            'adminHost' => self::adminHost($slug),
            'apiHost' => self::apiHost($slug),
            'ownerName' => 'Owner '.$slug,
            'ownerEmail' => 'owner@'.$company.'.test',
        ]);
    }

    /**
     * A unique slug for a tenant that a single test creates and must clean up.
     */
    public static function temporarySlug(): string
    {
        return 'tmp-'.strtolower(Str::random(8));
    }

    /**
     * Create a staff member with a known password inside the given brand.
     */
    public static function staff(Tenant $tenant, string $password = 'correct-horse-9', ?string $role = 'Manager'): StaffUser
    {
        return $tenant->run(function () use ($password, $role): StaffUser {
            $staff = StaffUser::query()->create([
                'name' => 'Staff '.Str::random(5),
                'email' => 'staff-'.strtolower(Str::random(10)).'@example.test',
                'password' => $password,
                'status' => 'active',
            ]);

            if ($role !== null) {
                $staff->assignRole($role);
            }

            return $staff;
        });
    }

    /**
     * Drop tenants a previous (possibly crashed) run created for a single test.
     */
    public static function dropTemporaryTenants(): void
    {
        foreach (Tenant::listOf(Tenant::query()->where('slug', 'like', 'tmp-%')) as $tenant) {
            self::destroy($tenant);
        }
    }

    public static function destroy(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        try {
            $tenant->run(fn () => app(SearchIndex::class)->dropCollections());
        } catch (Throwable) {
            // A tenant that crashed before its database existed has no collection either.
        }

        app(PostgresProvisioner::class)->dropDatabase(TenantDatabaseNames::forSlug($tenant->slug));

        // The landlord test database is owned by this suite; clean rows through the migrator
        // role, which (unlike the app role) may delete audit rows.
        $landlord = app('db')->connection('landlord_migrator');
        $landlord->table('tenant_migration_runs')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('tenant_restores')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('tenant_backups')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('company_owner_brand_links')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('impersonation_sessions')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('brand_switch_tokens')->where('to_tenant_id', $tenant->id)->delete();
        $landlord->table('tenant_databases')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('domains')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('tenant_feature_flags')->where('tenant_id', $tenant->id)->delete();
        $landlord->table('tenants')->where('id', $tenant->id)->delete();
    }
}
