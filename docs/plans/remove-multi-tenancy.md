# Plan: remove multi-tenancy (single-store conversion)

- **Status:** Draft, waiting for owner approval. No code has been changed.
- **Date:** 2026-10-03
- **Decision (owner):** the project becomes a normal **single-store application with one
  PostgreSQL database**. The same codebase may be installed separately for another client, with
  its own database and `.env`. Multi-tenancy is removed completely and must not be re-added.
- **Scope:** `platform-api` and `platform-web`. The root `Commerce_Platform_Master_Spec.md` and the
  docs under `platform-api/docs/` are updated in milestone R8.

### Legend

| Mark | Meaning |
|---|---|
| **REMOVE** | Delete. Only the tenancy model needs it. |
| **CONVERT** | Keep the capability and drop the tenant dimension (rename, move, simplify). |
| **KEEP** | No change, or only a comment or wording change. |

### Rule for the new design

Store identity and integrations are **per installation**, never hardcoded:

| Value | Source |
|---|---|
| Store name, storefront URL (domain), logo, currency, timezone, locale, country | Admin settings (`settings` table, `store.*`), seeded once by `store:install` |
| Theme id and theme tokens | Admin settings (`theme.*`) |
| Feature flags (wishlist, reviews, blog, abandoned cart…) | Admin settings (`features.*`), replacing plan defaults + tenant overrides |
| Payment method credentials, courier credentials | Admin, encrypted in the DB (already the case: `payment_methods.config`, `couriers`) |
| Mail from-name/address | Admin settings (`mail.*`), already the case |
| DB, Redis, Typesense, object storage, mail transport, APP_KEY, service secrets, hostnames | `.env` only |
| Cache/queue/search/storage namespaces (so two installs can share a Redis or Typesense server) | `.env`: `REDIS_PREFIX`, `HORIZON_PREFIX`, `SCOUT_PREFIX`, `OBJECT_STORAGE_PREFIX` |

---

## 1. Audit — platform-api

### 1.1 Packages (composer.json)

| Item | Mark | Notes |
|---|---|---|
| `stancl/tenancy` ^3.10 | **REMOVE** | Also drop it from `composer.lock`. Provides `tenant()`, `tenancy()`, `GlobalCache` and the bootstrappers. |
| `"Modules\\Platform\\"` PSR-4 entry | **REMOVE** | The module goes away (§1.4). |
| `description` ("Multi-brand commerce platform… landlord panel") | **CONVERT** | Single-store wording. |
| `test:isolation` composer script | **REMOVE** | The isolation suite goes away. |
| Every other dependency (Filament, Horizon, Sanctum, Scout, Typesense, spatie/*, Scramble, Pest, Larastan, Pint) | **KEEP** | |

### 1.2 Config

| File | Mark | What changes |
|---|---|---|
| `config/tenancy.php` | **REMOVE** | Bootstrappers, tenant/domain models, cache tags, filesystem suffixing. |
| `config/database.php` | **CONVERT** | Replace `landlord`, `landlord_migrator`, `provisioner`, `tenant_template` with **`pgsql`** (app role, via PgBouncer when `DB_POOLER_HOST` is set) and **`pgsql_migrator`** (migration role, direct). Default connection becomes `pgsql`. The `sqlite` fixture connection stays. |
| `config/platform.php` | **CONVERT** | Remove `name`, `host`, `topology`, `pinned_tenant`, `provisioning.*` (keep `pg_dump_binary`, `pg_restore_binary` and retention as `backup.*`), `impersonation`, `brand_switch`. Keep `staff`, `turnstile`, `panels`, `passwords`, `service_auth`, `storefront`, `imports`, `release`, `monitoring`. Rename the file to `config/store.php` (mechanical: `config('platform.` → `config('store.`). |
| `config/auth.php` | **CONVERT** | Remove the `platform` guard and the `platform_users` provider. Keep `staff` (session) and `customer` (Sanctum). |
| `config/filesystems.php` | **CONVERT** | `tenant_media` → `media`, `tenant_private` → `private`, with an optional root prefix `OBJECT_STORAGE_PREFIX` instead of `tenants/{id}/`. |
| `config/media-library.php` | **CONVERT** | `disk_name` default `media`. |
| `config/queue.php` | **CONVERT** | Remove the `central` connection and the tenant comments. `batching.database` and `failed.database` → `pgsql`. |
| `config/horizon.php` | **CONVERT** | `domain` → the admin host (`ADMIN_DOMAIN`, empty = any host); middleware `['web', 'auth:staff']` plus a `viewHorizon` gate on a new `system.monitor` staff permission instead of `EnsurePlatformHost` + `auth:platform`. |
| `config/scramble.php` | **CONVERT** | Same gate as Horizon for `/docs/api`. The API description no longer says "scoped to the brand of the API host". This changes the committed contract text only (non-breaking), so `docs/api/openapi-v1.json` is regenerated. |
| `config/scout.php` | **CONVERT** | Comment only; the collection becomes `{SCOUT_PREFIX}products`. |
| `config/logging.php` | **KEEP** | Comment says `tenant_id`; becomes request id only. |
| `config/cache.php`, `session.php`, `sanctum.php`, `services.php`, `app.php`, `mail.php`, `hashing.php` | **KEEP** | |
| `phpunit.xml` | **CONVERT** | Remove the `Isolation` testsuite and the `TENANT_*`, `PLATFORM_HOST`, `TENANCY_*` and `DB_LANDLORD_*` env vars; add `DB_DATABASE=store_test` (+ migrator). Keep `SCOUT_PREFIX=test_`. |
| `phpstan.neon`, `pint.json` | **KEEP** | Check for path excludes that mention Platform. |

### 1.3 Bootstrap, app layer, routes

| File | Mark | What changes |
|---|---|---|
| `bootstrap/app.php` | **CONVERT** | Remove the `tenant` and `platform.host` aliases and the `InitializeTenancyByHost` / `InitializeTenancyForPanelRequest` priority entries. Re-anchor `ApiCachePolicy` / `TrustStorefrontService` priorities on `EncryptCookies`. `feature` points at the moved middleware (§1.4). |
| `bootstrap/providers.php` | **CONVERT** | Remove `PlatformServiceProvider` and `PlatformPanelProvider`. |
| `app/Providers/Filament/PlatformPanelProvider.php` | **REMOVE** | The platform/landlord panel. |
| `app/Providers/Filament/AdminPanelProvider.php` | **CONVERT** | Remove the `tenant:admin`, `ScopeSessionToTenant` and `EnforceImpersonationExpiry` middleware and the brand-switcher render hook. `brandName` reads the `store.name` setting. Optional `->domain(ADMIN_DOMAIN)`. Add the Settings page and the launch checklist. |
| `app/Providers/AppServiceProvider.php` | **CONVERT** | Rate-limiter keys lose the tenant prefix. The Horizon gate uses staff + `system.monitor`. The Livewire update route drops the tenancy middleware. |
| `app/Console/Commands/SeedDemoCatalogCommand.php` | **CONVERT** | → `store:seed-demo` (defaults + demo catalog + `--restock`), with no `TenantDirectory` loop and no slug-based SKU prefix. |
| `app/OpenApi/*`, `ApiContractCommand` | **KEEP** | |
| `resources/views/filament/brand-switcher.blade.php` | **REMOVE** | |
| `routes/web.php` | **CONVERT** | `GET /health` without `platform.host`, using the moved `HealthController`. |
| `routes/console.php` | **CONVERT** | Dispatch each job directly (`PublishOutboxMessages::dispatch()` etc.) instead of `DispatchToActiveTenants`. Rename tasks from `tenants:*` to plain names. `tenants:backup` → `db:backup` (if decision D4 = keep). |
| `modules/Platform/routes/admin.php` (`_handoff/{kind}/{token}`, `_switch/{tenant}`) | **REMOVE** | Support hand-off and brand switcher. |
| Module `routes/api_v1.php` group middleware `tenant:api` (set in `ModuleServiceProvider`) | **CONVERT** | Group becomes `['api', ApiCachePolicy, TrustStorefrontService]`. URLs are unchanged, so API v1 is unaffected. |

### 1.4 The Platform module (`modules/Platform`, about 100 files)

Most of it goes. The pieces that other modules depend on move first (milestone R2).

| Item | Mark | Destination / notes |
|---|---|---|
| `Contracts/FeatureFlags`, `Support/PlanFeatureFlags` | **CONVERT** | → `Modules\Settings\Contracts\FeatureFlags` + `SettingsFeatureFlags` (the `features.*` settings group; `DEFAULTS` kept; no plan, no `GlobalCache`). |
| `Http/Middleware/EnsureFeatureEnabled` | **CONVERT** | → `Modules\Settings\Http\Middleware\EnsureFeatureEnabled`. The `403 feature_disabled` envelope is unchanged. |
| `Contracts/OnboardingCheck`, `Contracts/OnboardingChecks`, `Support/LaunchChecklist` | **CONVERT** | → Settings module. The landlord facts (domains, plan, theme) become settings checks (storefront URL set, logo set, theme chosen). Shown on an admin page instead of the platform panel. |
| `Http/Controllers/HealthController` | **CONVERT** | → `Modules\Support\Http\HealthController`, probing the `pgsql` connection. |
| `Actions/MigrateTenant` | **CONVERT** | → `MigrateDatabase` (+ `app:migrate` command): every pending migration in **one** transaction as `pgsql_migrator`, then re-apply the append-only `REVOKE`s to the app role (spec §6.3). |
| `Actions/SeedTenant` | **CONVERT** | → `SeedDefaults`, which runs every module's default seeders (also called from `DatabaseSeeder`). |
| `Actions/ExportTenant`, `PruneTenantExports`, `Console/TenantExportCommand`, `TenantsBackupCommand` | **CONVERT** | → `db:backup` (pg_dump + checksum + retention). Depends on decision D4. |
| `Actions/RestoreTenant`, `Console/TenantRestoreCommand` | **CONVERT** | → `db:backup --drill` (restore into a scratch DB and compare). Depends on decision D4. |
| `Actions/ReencryptAllSecrets`, `Console/SecretsReencryptCommand` | **CONVERT** | → Support module, one scope (there is no "central vs tenant" any more). |
| `Actions/CreateTenant` | **CONVERT** (partly) | Its reusable steps (seed defaults, create the search collection, create the owner account) become `InstallStore` + `store:install`. Database and role creation and domain rows are removed. |
| `Actions/CreateCompany`, `CreatePlatformUser`, `ChangeTenantStatus`, `DeleteTenant`, `DispatchToActiveTenants`, `IssueBrandSwitch`, `ConsumeHandoff`, `StartImpersonation`, `MigrateLandlord`, `MigrateTenants`, `RotateTenantDatabasePasswords`, `SeedDemoPlatform` | **REMOVE** | |
| `Console/*` (14 commands): `company:create`, `landlord:migrate`, `platform:seed-demo`, `platform:user`, `tenant:create`, `tenant:suspend`, `tenant:activate`, `tenant:delete`, `tenants:migrate`, `tenant:rotate-db-passwords` | **REMOVE** | Replaced by `store:install`, `app:migrate`, `store:seed-demo`. The `secrets:reencrypt`, export, backup and restore commands are converted (rows above). |
| `Contracts/OwnerBrands`, `Contracts/TenantDirectory` | **REMOVE** | `TenantDirectory::storefrontUrl()` is replaced by the `store.url` setting; `runIn` / `activeTenantIds` loops are deleted. |
| `Data/*` (`CompanyData`, `TenantData`, `ProvisioningResult`), `Enums/*` (`DomainKind`, `PlatformRole`, `ProvisioningStep`, `TenantStatus`), `Exceptions/*` | **REMOVE** | |
| `Filament/Resources/Companies/*`, `Filament/Resources/Tenants/*` | **REMOVE** | |
| `Http/Controllers/BrandSwitchController`, `HandoffController` | **REMOVE** | |
| `Http/Middleware/InitializeTenancyByHost`, `InitializeTenancyForPanelRequest`, `EnsurePlatformHost`, `ScopeSessionToTenant`, `EnforceImpersonationExpiry` | **REMOVE** | |
| `Listeners/AuditPlatformAuthentication` | **REMOVE** | Staff login audit (`AuditStaffAuthentication`) stays. |
| Models: `Tenant`, `Domain`, `TenantDatabase`, `TenantFeatureFlag`, `TenantMigrationRun`, `TenantBackup`, `TenantRestore`, `Company`, `CompanyOwnerIdentity`, `CompanyOwnerBrandLink`, `BrandSwitchToken`, `ImpersonationSession`, `Plan`, `PlatformUser`, `PlatformActivity`, `PlatformLoginAudit`, `LandlordModel` | **REMOVE** | |
| `Support/AuthorizesPlatformActor`, `LandlordTenantDirectory`, `OwnerBrandDirectory`, `PlatformAudit`, `Provisioning/PostgresProvisioner`, `Provisioning/TenantDatabaseNames` | **REMOVE** | The backup drill (if kept) needs only "create/drop scratch DB", which moves into the backup action. |
| `Tenancy/TenantDatabaseBootstrapper`, `TenantDatabaseConfig`, `TenantLogContextBootstrapper`, `TenantResolver` | **REMOVE** | |
| `Providers/PlatformServiceProvider`, `composer.json` | **REMOVE** | |
| `database/migrations/landlord/*` (5 files) | **REMOVE** | `failed_jobs` and `job_batches` are recreated by a new migration in `database/migrations/` (§1.6). `releases` and `tenant_metrics_daily` are unused and dropped. |

### 1.5 Other modules and the Support kernel

| File | Mark | What changes |
|---|---|---|
| `Support/Providers/ModuleServiceProvider` | **CONVERT** | Drop `tenantMigrationPath()` / `landlordMigrationPath()`; call `loadMigrationsFrom(<module>/database/migrations)`. `tenantSeeders()` → `defaultSeeders()`. API route group without `tenant:api`. Fix the docblocks. |
| `Support/Modules/ModuleRegistry` | **CONVERT** | Keep modules, append-only tables, default seeders and encrypted columns; drop migration-path bookkeeping and the scope filter. |
| `Support/Security/EncryptedColumn` | **CONVERT** | Drop `CENTRAL` / `TENANT` scope (`EncryptedColumn::make($table, $column)`). Every module's `encryptedColumns()` call follows. |
| `Support/Events/StoredSecretsReencrypted`, `Support/Actions/ReencryptStoredSecrets`, `Support/Contracts/ReencryptsStoredSecrets` | **CONVERT** | Drop the scope argument. |
| `Support/Queue/TenantAware`, `EnsureTenantContext`, `MissingTenantContext` | **REMOVE** | |
| `Support/Monitoring/PostHogErrors` | **CONVERT** | No `tenant` property; keep the request id and release. |
| `Support/Http/VerifyHumanChallenge` | **CONVERT** | Replay key without the tenant prefix. |
| `Identity/Support/PermissionCacheBootstrapper` | **REMOVE** | spatie/permission's own cache key is enough with one database. |
| `Identity/Support/LoginThrottle` | **CONVERT** | Throttle keys without the tenant. |
| `Identity/Models/StaffUser` | **CONVERT** | `canAccessPanel()` without `tenancy()->initialized`; remove `landlord_owner_identity_ulid` (and add a migration dropping the column). |
| `Identity/Listeners/AuditStaffAuthentication` | **CONVERT** | Remove the "no tenant → skip" guard. |
| `Identity/Contracts/StaffDirectory` + `EloquentStaffDirectory` | **CONVERT** | Keep `ensureOwner()` (used by `store:install`). Remove methods only impersonation and the brand switch used (sign in from a hand-off, mirror platform events); confirmed against callers at implementation. |
| `Identity/Actions/RequestCustomerPasswordReset` | **CONVERT** | Link origin from the `store.url` setting. |
| `Identity/Enums/StaffPermission`, `StaffRole` | **CONVERT** | Add `system.monitor` (Horizon, API docs), granted to Owner. The `StaffRolesSeeder` convergence handles existing installs. |
| `Settings/Contracts/TenantSettings`, `Support/DatabaseTenantSettings` | **CONVERT** | Rename to `StoreSettings` / `DatabaseStoreSettings` (mechanical, about 20 call sites). |
| `Settings/Seeders/DefaultSettingsSeeder` | **CONVERT** | Defaults come from `store:install` options or `.env` (`APP_NAME`, `STOREFRONT_URL`), not `tenant()`. Add `store.url`, `store.logo`, `features.*`. |
| `Settings/Http/Controllers/V1/StorefrontConfigController` | **CONVERT** | Flags from Settings. **Additive** fields `store.url` and `store.logo_url`, which the v1 freeze allows. |
| `Settings/Providers/SettingsServiceProvider`, `Actions/UpdateSetting`, `Support/StoreEmailCheck` | **CONVERT** | New contracts, no scope filter. |
| *(new)* `Settings/Filament/Pages/StoreSettingsPage` | **CONVERT** (new) | There is **no admin settings screen today**. Add one: store info, logo upload, theme id + tokens, SEO, mail, feature flags. Gated by `settings.manage`; calls `UpdateSetting`. |
| `Catalog/Support/ProductSearch` | **CONVERT** | `searchableAs()` = `{SCOUT_PREFIX}products`; no tenant check. |
| `Catalog/Support/TypesenseSynonyms` | **CONVERT** | Set name `{SCOUT_PREFIX}synonyms`. |
| `Catalog/Actions/ReindexSearch`, `Console/SearchReindexCommand` | **CONVERT** | One reindex, no `--tenant`. |
| `Catalog/Support/CatalogLaunchCheck`, `Content/Support/HomePageCheck`, `Payments/Support/PaymentsLaunchCheck`, `Shipping/Support/ShippingLaunchCheck`, `Orders/Support/TestOrderCheck` and their providers | **CONVERT** | Import the moved contracts. |
| `Content/Support/ArticleSitemapSource`, `Cart/Actions/SendCartReminders` | **CONVERT** | Import the moved `FeatureFlags`. |
| `Notifications/Handlers/CartAbandonedEmail` | **CONVERT** | Storefront origin from `store.url`. |
| `Integrations/Support/RevalidateStorefront` | **CONVERT** | Origin from `store.url` (or `STOREFRONT_REVALIDATE_URL`); request body without `site`. |
| Jobs: `Integrations/PublishOutboxMessages`, `Inventory/RunInventoryMaintenance`, `Orders/CancelUnpaidOrdersJob`, `Orders/SyncShipmentTrackingJob`, `Reporting/RefreshReportsJob`, `Cart/SendCartRemindersJob`, `Imports/RunImportJob` | **CONVERT** | Drop `use TenantAware`; unique ids without `tenant()` (`'outbox'`, `'reports-refresh'`). |
| `Reporting/Console/ReportsRefreshCommand`, `ReportsVerifyCommand`, `Imports/Console/ImportCsvCommand` | **CONVERT** | No `TenantDirectory` loop and no `--tenant`. |
| Default seeders: `StaffRolesSeeder`, `DefaultLocationSeeder`, `DefaultShippingSeeder`, `DefaultPaymentMethodsSeeder`, `DefaultNotificationTemplatesSeeder`, `DefaultContentSeeder` | **KEEP** | Registered through `defaultSeeders()`; wording in docblocks only. |
| `database/seeders/DatabaseSeeder` | **CONVERT** | Runs `SeedDefaults` (today it is intentionally empty). |
| Catalog `Brand` model / `brands` table | **KEEP** | Product brands (manufacturer), not tenants. Unrelated, but easy to confuse. |
| Comments/docblocks in about 30 other module files ("brand", "tenant", "per tenant") | **CONVERT** | Wording only, in the module that is touched anyway. |

### 1.6 Migrations

| Item | Mark | What changes |
|---|---|---|
| `modules/*/database/migrations/tenant/*.php` (30 files across 18 modules) | **CONVERT** | Move to `modules/*/database/migrations/` (drop the `tenant/` level), **same filenames**, loaded with `loadMigrationsFrom`. Because filenames are unchanged, an existing tenant database can be adopted as the store database with nothing re-run (decision D2). Comments that mention "tenant" or "landlord" are fixed. |
| `modules/Platform/database/migrations/landlord/*.php` (5 files) | **REMOVE** | |
| *(new)* `database/migrations/2026_10_04_000001_create_queue_tables.php` | **CONVERT** (new) | `failed_jobs`, `job_batches` (moved from the landlord DB). |
| *(new)* `modules/Identity/database/migrations/…_drop_landlord_owner_identity_from_users.php` | **CONVERT** (new) | Forward migration; the original create-table migration stays as is (already applied in existing DBs). |
| *(new)* `modules/Catalog/database/migrations/…_rename_media_disks.php` | **CONVERT** (new) | `UPDATE media SET disk='media' WHERE disk='tenant_media'` (and `conversions_disk`). Only if decision D6 = rename. |
| `tests/Support/Migrations/Failing/*` | **KEEP** | Used by the converted migrate test. |

### 1.7 Database setup, Docker, deploy, CI

| Item | Mark | What changes |
|---|---|---|
| `database/setup/bootstrap-landlord.sql` | **CONVERT** | → `bootstrap-database.sql`: roles `store_app` (DML only), `store_migrator` (owns schema), `backup_reader` (SELECT); one database (name passed as a variable); `CONNECT` revoked from PUBLIC; default privileges. **No provisioner role, no CREATEDB/CREATEROLE.** |
| `docker/postgres/01-roles.sql` | **CONVERT** | Same three roles for the local Compose DB. |
| `docker-compose.yml` → `postgres` | **CONVERT** | `POSTGRES_DB` → `store`; healthcheck db name; header comment (no demo companies or brands). |
| `docker-compose.yml` → `pgbouncer`, `redis`, `typesense`, `mailpit` | **KEEP** | Typesense image tag 27.1 vs CI 30.2 is a pre-existing mismatch; noted, not part of this work. |
| `docker-compose.yml` → `minio`, `minio-init` | **CONVERT** | Public-read path `local/platform-local/tenants` → the media prefix. |
| `deploy/release.sh` | **CONVERT** | `landlord:migrate` + `tenants:migrate` → `app:migrate` (still blocks the release on failure). |
| `deploy/rollback.sh` | **KEEP** | Check for tenant wording. |
| `deploy/tests/release.test.sh` | **CONVERT** | Stub expectations follow the new command. |
| `.github/workflows/ci.yml` | **CONVERT** | Bootstrap one test DB with `bootstrap-database.sql`; drop `DB_PROVISIONER_PASSWORD` / `DB_LANDLORD_*`; drop the "Tenant isolation tests" step; the openapi job uses `DB_*`. Keep the architecture step. |
| `loadtest/storefront.js`, `service-times.ps1`, `generate-catalog.mjs`, `README.md` | **CONVERT** | Hostnames (`api.alpha.localhost`) → one API URL. |

### 1.8 Environment variables (`.env.example`)

| Variable | Mark | Replacement |
|---|---|---|
| `TENANCY_TOPOLOGY`, `TENANCY_PINNED_TENANT`, `PLATFORM_HOST` | **REMOVE** | |
| `DB_CONNECTION=landlord` | **CONVERT** | `DB_CONNECTION=pgsql` |
| `DB_LANDLORD_HOST/PORT/DATABASE/USERNAME/PASSWORD/SSLMODE` | **CONVERT** | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` |
| `DB_LANDLORD_MIGRATOR_USERNAME/PASSWORD` | **CONVERT** | `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` |
| `DB_PROVISIONER_*`, `TENANT_DB_NAME_PREFIX`, `TENANT_DB_ROLE_PREFIX`, `DB_TENANT_*` | **REMOVE** | |
| `TENANT_EXPORT_RETENTION`, `TENANT_EXPORT_DISK` | **CONVERT** | `BACKUP_RETENTION`, `BACKUP_DISK` (if D4 = keep) |
| `IMPERSONATION_TTL_MINUTES`, `BRAND_SWITCH_TTL_SECONDS`, `PLATFORM_NAME` | **REMOVE** | Store name is a setting. |
| `HORIZON_DOMAIN` | **CONVERT** | `ADMIN_DOMAIN` (also used by the admin panel and API docs; empty = any host) |
| *(new)* `API_DOMAIN` | **CONVERT** (new) | Optional; if set, API routes answer only on it (decision D1). |
| *(new)* `STOREFRONT_URL` | **CONVERT** (new) | Install-time default for the `store.url` setting. |
| *(new)* `OBJECT_STORAGE_PREFIX` | **CONVERT** (new) | Root prefix inside the bucket (replaces `tenants/{id}/`). |
| `APP_URL`, `DB_POOLER_*`, `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_*`, `SCOUT_*`, `TYPESENSE_*`, `OBJECT_STORAGE_DRIVER`, `R2_*`, `MAIL_*`, `SERVICE_AUTH_*`, `STOREFRONT_REVALIDATE_*`, `HORIZON_PREFIX`, `STAFF_2FA_REQUIRED`, `PAYMENTS_SANDBOX`, `POSTHOG_*`, `MONITORING_*`, `HEALTH_CHECK_TOKEN`, `PG_DUMP_BINARY`, `PG_RESTORE_BINARY`, `TURNSTILE_SECRET_KEY` | **KEEP** | Comments that say "tenant" or "per brand" are reworded. |

### 1.9 Tests

| Item | Mark | What happens |
|---|---|---|
| `tests/Support/TestPlatform.php` | **REMOVE** | → `tests/Support/TestStore.php`: once per process, run `app:migrate`, `SeedDefaults` and a search reindex against `store_test`. Tests keep writing uniquely named data, as now. |
| `tests/TestCase.php` | **CONVERT** | `TestStore::prepare()`; no tenancy teardown. |
| `tests/Pest.php` | **CONVERT** | Remove the `isolation` group and the `Isolation` directory binding. |
| `tests/Isolation/SearchIsolationTest.php` | **CONVERT** | → `Feature/Catalog/SearchTest.php`: keep typos, exact SKUs, published-only, synonyms and `in_stock`; drop "sibling brand". |
| `tests/Isolation/AdminAndTopologyIsolationTest.php` | **CONVERT** (small part) | Keep "admin panel only on the admin host" if D1 keeps hosts; the rest is removed. |
| `tests/Isolation/DataAndApiIsolationTest`, `CommerceIsolationTest`, `CacheAndStorageIsolationTest`, `QueueAndSchedulerIsolationTest` | **REMOVE** | Before deleting `CommerceIsolationTest`, any customer-vs-customer (IDOR) assertion is moved into the Commerce feature tests. A new `ScheduleTest` checks that every scheduled job is registered. |
| `tests/Feature/Platform/DatabasePrivilegeTest` | **CONVERT** | → `Feature/Support/DatabasePrivilegeTest`: app role has no DDL and no superuser/CREATEDB/CREATEROLE, and append-only tables reject UPDATE/DELETE/TRUNCATE. Cross-database cases are dropped. |
| `tests/Feature/Platform/TenantMigrateTest` | **CONVERT** | → `Feature/Support/MigrateTest`: one failing migration among several rolls everything back; seeders converge without undoing role edits; non-zero exit. |
| `tests/Feature/Platform/SecretsRotationTest` | **CONVERT** | Keep "every encrypted cast declared" and "re-encrypt and retire the old key"; drop DB role password rotation. |
| `tests/Feature/Platform/FeatureFlagTest` | **CONVERT** | Flags from settings; the 403 `feature_disabled` case stays. |
| `tests/Feature/Platform/RequestContextTest` | **CONVERT** | Request id only. |
| `tests/Feature/Platform/LaunchChecklistTest` | **CONVERT** | Checklist page in the store admin. |
| `tests/Feature/Platform/PanelAccessTest` | **CONVERT** | Keep the staff list, hiding staff management without permission, and owner-only owner creation; Horizon for staff with `system.monitor`. |
| `tests/Feature/Platform/RestoreDrillTest`, `TenantLifecycleTest` (export case) | **CONVERT** | → `Feature/Support/BackupTest` (if D4 = keep). |
| `tests/Feature/Platform/TenantResolutionTest` | **CONVERT** (small part) | Keep "app traffic through the pooler, migrations around it" as a config test; the rest is removed. |
| `tests/Feature/Platform/CreateTenantTest`, `HandoffTest`, `LandlordPurityTest`, `TenantLifecycleTest` (rest) | **REMOVE** | Replaced by `Feature/Support/StoreInstallTest`. |
| `tests/Architecture/BoundariesTest` ("landlord only from Platform") | **CONVERT** | → **guard test**: no `Stancl\`, `tenancy(`, `tenant(`, `landlord`, `TenantAware` or `Modules\Platform` anywhere in `app/`, `modules/`, `config/`, `routes/`, `bootstrap/`, and no `stancl/tenancy` in `composer.json`. This is what stops tenancy from being re-added. |
| `tests/Support/Jobs/WriteMarkerJob.php` | **REMOVE** | Used only by the queue isolation test. |
| `tests/Support/Concurrency/reserve.php` | **CONVERT** | Boots the app without initialising a tenant. |
| About 40 feature test files using `TestPlatform::brand()`, `$tenant->run()`, `apiHost()` / `adminHost()` (454 occurrences) | **CONVERT** | Mechanical: unwrap `run()`, call the default host. No assertion about store behaviour changes. |
| `tests/Feature/Api/ContractTest` | **KEEP** | Plus the two additive config fields. |

**Expected test count:** the 394 passing tests drop by roughly 70–80 (tenancy, isolation, hand-off, provisioning), with about 10 new ones (settings page, install, guard test, schedule). The exact number is reported after R4.

---

## 2. Audit — platform-web

| Item | Mark | What changes |
|---|---|---|
| `apps/storefront/src/middleware.ts` | **CONVERT** | No host check, no `isBrand()` lookup and no rewrite to `/<host>/…`. Keeps only the `x-request-id` header. |
| `apps/storefront/src/lib/site.ts` | **CONVERT** | `siteFromHost`, `siteFromParam` and `STOREFRONT_PINNED_HOST` are removed. `siteOrigin()` returns `config.store.url` (API) with the `STOREFRONT_URL` env as fallback. |
| `apps/storefront/src/app/[site]/**` (34 files) | **CONVERT** | Move to `apps/storefront/src/app/**` (the `[site]` segment is removed) and drop the `site` param, `siteFromParam` and `generateStaticParams`. |
| `apps/storefront/src/lib/api.ts` | **CONVERT** | `upstream()` uses `API_URL` (internal or public); no `api.${site}` and no `X-Forwarded-Host`. The signed service headers stay. |
| `apps/storefront/src/lib/storefront.ts` | **CONVERT** | `getStorefront()` takes no site; the `Storefront.site` field is removed. |
| `apps/storefront/src/lib/tags.ts` | **CONVERT** | Tags without the `${site}|` prefix; `scoped()` is removed. |
| `apps/storefront/src/app/api/revalidate/route.ts` | **CONVERT** | No `site` in the body; tags are revalidated as sent (allow-list and HMAC unchanged). During the transition it accepts a body with or without `site`. |
| `apps/storefront/src/lib/session.ts` | **CONVERT** | `currentSite()` is removed (cookies are already per-domain). |
| `src/lib/catalog.ts`, `cart.ts`, `seo.tsx`, `actions/*.ts`, `components/analytics.tsx`, `auth-forms.tsx`, `bff/*` routes | **CONVERT** | Drop the `site` argument everywhere. |
| `apps/storefront/src/instrumentation.ts` | **CONVERT** | No `brand` / `x-sf-site`; `distinct_id: "storefront"`. |
| `apps/storefront/src/lib/themes.ts` + `packages/theme-engine` | **KEEP** | One storefront, one active theme chosen by the `theme.id` setting (tokens from `theme.tokens`). The registry stays so another client's install can ship its own theme package. No host-based theme selection exists after the change. |
| `themes/default`, `themes/velvina-home` | **KEEP** | Which one this install uses is a setting (decision D3). |
| `packages/commerce-core`, `ui`, `sections`, `api-client` | **KEEP** | Already tenant-free (`siteKey` there is the Turnstile key). `api-client` is regenerated for the two additive config fields. |
| `apps/storefront/next.config.ts` | **CONVERT** | Comments only (pinned host, "brand's API"). |
| `apps/storefront/.env.example` | **CONVERT** | Remove `STOREFRONT_PINNED_HOST`; `API_INTERNAL_URL` → `API_URL`; add `STOREFRONT_URL`. |
| `apps/storefront/.env.local` | **CONVERT** | Local values (not committed). |
| `apps/storefront/playwright.config.ts`, `e2e/support.ts` | **CONVERT** | `baseURL` `http://localhost:3000`; the `brand()` helper is removed. |
| `apps/storefront/e2e/theme.spec.ts` | **CONVERT** | "Renders the configured theme", "unknown path is a 404 in the store layout", "robots/sitemap use the store URL"; the two-hostname and unknown-hostname tests are removed. |
| `apps/storefront/e2e/global-setup.ts`, `account.spec.ts`, `browse.spec.ts`, `purchase.spec.ts` | **CONVERT** | `store:seed-demo --restock`; no `brand(...)` URLs. |
| `apps/storefront/src/lib/client-ip*.ts`, `crypto*.ts`, `fonts.ts` | **KEEP** | |
| `.github/workflows/ci.yml`, `README.md`, root `package.json` description | **CONVERT** | Wording, and the seed command names in CI comments. |

---

## 3. Documents to update

### 3.1 ADRs

| ADR | Outcome |
|---|---|
| 0001 Multi-database tenancy with stancl/tenancy | **Superseded** by ADR-0020 |
| 0005 Typesense with per-tenant collections | **Superseded** by ADR-0020 (server-side querying through the API stays; collection naming moves to 0014) |
| 0012 Tenant scoping of cache, sessions and framework singletons | **Superseded** by ADR-0020 |
| 0013 Company owner identity and hand-off tokens | **Superseded** by ADR-0020 |
| 0002 API host strategy | **Amended**: hosts come from `.env` (`ADMIN_DOMAIN`, `API_DOMAIN`); no `domains` table; webhooks on the one API host |
| 0004 Cloudflare R2 with per-tenant prefixes | **Amended**: one configurable prefix per installation |
| 0014 Typesense 30 specifics | **Amended**: synonym set / collection names `{SCOUT_PREFIX}…` |
| 0016 API contract tooling and v1 freeze | **Amended**: CI DB setup; note the two additive config fields |
| 0017 Storefront BFF sessions, service auth, revalidation | **Amended**: unscoped tags; revalidate body without `site` |
| 0019 Monitoring with PostHog | **Amended**: no tenant id in events |
| 0003, 0009, 0011 | Wording only ("tenant", Platform module, per-tenant dirs) |
| 0006, 0007, 0008, 0010, 0015, 0018 | Unchanged |
| **New 0020** Single-store application; multi-tenancy removed | Records the decision, the per-installation model, the settings-vs-`.env` rule, and the guard test |

Superseded ADRs are not deleted: they get `Status: Superseded by ADR-0020` and stay as history. `docs/adr/README.md` index is updated (CI checks it).

### 3.2 Master spec (`Commerce_Platform_Master_Spec.md`) → version 2.0

| Section | Change |
|---|---|
| Title, header, §0 intro | "Multi-Brand Commerce Platform" → single-store commerce application, installable per client; v2.0 note |
| §1.1, §1.2 | Rewrite: one installation per client store (own DB, own `.env`, own deploy); no companies/brands-as-tenants |
| §1.3 | "tenant settings" → store settings |
| §1.4 goals 1–3 | "Build once, install per client"; isolation by separate installation; scale = more installations |
| §2 stack table | Remove the Multi-tenancy row; Database = one DB per installation; PgBouncer, Scheduler, Backups, Storefront, Styling, Monorepo and Admin rows lose "per tenant" and "landlord"; "Explicitly not used" adds multi-tenancy packages |
| §3.1 diagram, §3.2 repos, §3.3 module table | Remove the landlord DB and panel; remove the `Platform` module; Settings = store settings and feature flags |
| **§4 Multi-tenancy architecture** | **Replaced** by "Single-store architecture and per-client installations": the no-multi-tenancy rule, settings vs `.env`, install procedure, namespacing for shared infrastructure |
| §5, §5.1 | "Common vs per-tenant" → "Core vs per-installation configuration"; ladder rung 5 = client theme package |
| §6.1, §6.2, §6.3 | Rate limits without tenant; two user populations (staff, customers); DB roles = app / migrator / backup for one DB; transactional `app:migrate` |
| §6.5, §6.6, §6.8–§6.14 | Remove "per tenant" (locations, credentials, templates, storage prefix, collections and synonyms, scheduler, two panels → one admin, robots, adapters) |
| §7.1–§7.6 | Rewrite §7.1 (no host resolution; config from the API); themes = selectable theme packages; "each tenant storefront" → the store |
| §8 | Remove tenant isolation tests and platform users; credentials "in the store DB" |
| §9, §10, §11 | Logs without tenant id; one DB backup and restore drill; runbook list; local env = one store with demo data; `app:migrate` in CD; WooCommerce importer per installation |
| §12.2, §12.4, §12.5 | Isolation tests → guard test; docs list (no tenancy.md / tenant-onboarding.md); DoD "tenant-aware" removed |
| §13 roadmap | Rationale paragraph; Phase 1 milestones marked historical; Phase 8 → "Launch & client installations" |
| §14, §15, §16, §17 | MVP without tenancy; DoD items 1, 2, 4, 5 replaced (installable per client; guard test), 3 kept as "migrations transactional"; open decisions 5–7 removed; §17 marked historical |

### 3.3 PROGRESS.md

- Header: current state = "Single-store conversion in progress (plan: remove-multi-tenancy.md)"; next = Phase 8 "Launch & client installation"; the test count is updated after R4.
- Phase 1 rows 1.2, 1.3, 1.4, 1.6, 1.7, 1.8: add "superseded by single-store conversion" notes. Rows 2.3, 5.2, 5.9 and 7.5 ("cached tenant resolution") get the same.
- New section **"Single-store conversion (R0–R9)"** with a status table, updated per milestone.

### 3.4 Phase plans and checkpoints

These are historical records and are **not rewritten**. Each gets a banner: *"Superseded in part by [remove-multi-tenancy.md](remove-multi-tenancy.md): tenancy, landlord, platform panel and isolation content below no longer describe the system."* Affected sections:

- `plans/phase-1.md`: 1.2, 1.3, 1.4, 1.5 (platform users), 1.6, 1.7, 1.8, sequencing, risks (most of the file)
- `plans/phase-2.md`: 2.3 Search, 2.6 Media (per-tenant prefixes)
- `plans/phase-3.md`: one mention
- `plans/phase-5.md`: Architecture (host resolution), API access, Tests
- `plans/phase-6.md`: 6.8 onboarding checklist in the platform panel
- `plans/phase-7.md`: backup/restore drill, cached tenant resolution, deploy with `tenants:migrate`
- `phase-0…7-checkpoint.md`: banner only

### 3.5 Other docs

| Doc | Outcome |
|---|---|
| `architecture/tenancy.md` | **REMOVE** → `architecture/installation.md` (per-client install model, settings vs `.env`, shared-infrastructure prefixes) |
| `architecture/overview.md`, `modules.md`, `data-model.md` (landlord ERD removed), `database-roles.md` (rewritten for one DB), `api-conventions.md` (rate limits, hosts) | **CONVERT** |
| `runbooks/provision-tenant.md` | → `install-store.md` |
| `runbooks/restore-tenant.md` | → `restore-database.md` |
| `runbooks/verify-isolation.md` | **REMOVE** |
| `runbooks/deploy.md`, `rollback.md`, `rotate-secrets.md`, `incident-response.md`, `README.md` | **CONVERT** |
| `tenant-onboarding.md` | → `store-launch-checklist.md` |
| `security/asvs-l2.md`, `performance/load-test.md` | **CONVERT** (tenant-related rows) |
| `platform-api/README.md`, `platform-web/README.md` | **CONVERT** |

---

## 4. Decisions needed before R1 (my recommendation first)

| # | Question | Recommendation |
|---|---|---|
| D1 | Hosts for admin and API | **Keep `admin.<domain>` and `api.<domain>`**, set in `.env` (`ADMIN_DOMAIN`, `API_DOMAIN`, empty = any host). It keeps ADR-0002's WAF and CORS separation and the mobile-app API host. |
| D2 | Existing local data (landlord + `alpha`/`bravo`/`charlie` tenant DBs) | **Start fresh**: new `store` DB + `store:seed-demo`. Nothing is in production. Alternative: adopt the `bravo` (Velvina Home) tenant DB as the store DB; this is possible because migration filenames are unchanged. Old DBs are dropped only after you confirm. |
| D3 | Theme of this installation | **`velvina-home`** as this install's `theme.id`, `default` kept as the fallback theme package. |
| D4 | Backups | **Keep an app-level `db:backup`** (nightly pg_dump + weekly restore drill), because hosting is not chosen and spec §9 requires a tested restore. Revisit when the host offers PITR. |
| D5 | Where migrations live | **Per module** (`modules/<M>/database/migrations/`): it keeps the modular structure and the filenames. Alternative: everything in `database/migrations/`. |
| D6 | Disk names | **Rename** `tenant_media`/`tenant_private` → `media`/`private`, with a data migration for `media.disk`. |
| D7 | Who gets Horizon and API docs | **Staff with the new `system.monitor` permission** (Owner only by default). Platform users no longer exist. |

---

## 5. Milestones

Every milestone ends with: app boots, `app:migrate` (or, before R3, the existing migrate commands) runs, **Pint, Larastan level 8 and the full Pest suite pass**, and for web milestones ESLint, typecheck, Vitest and `next build` pass. PROGRESS.md is updated at each one. Work happens on a branch `single-store` in both repos.

### R0 — Baseline and safety net (no code)

- Create the `single-store` branch in `platform-api` and `platform-web`.
- Run and record the baseline: full Pest suite (394), Larastan, Pint, web checks, E2E.
- `pg_dump` the local landlord and tenant DBs into a backup folder outside the repo.
- **Tests:** baseline green, recorded in PROGRESS.md.

### R1 — Store identity and feature flags live in settings (additive; tenancy still on)

- New settings `store.url`, `store.logo`, `features.*` (seeded from today's plan defaults).
- Move `FeatureFlags` + `EnsureFeatureEnabled` into Settings, backed by `features.*`.
- Replace every `TenantDirectory::storefrontUrl()` call (password reset, cart reminder, revalidation) with `store.url`.
- Admin **Store settings** page (store info, logo, theme id + tokens, SEO, mail, flags), gated by `settings.manage`, calling `UpdateSetting`.
- `GET /storefront/config` gains `store.url`, `store.logo_url` (additive); `api:contract` regenerated; web client synced.
- **Tests:** flags from settings + 403 `feature_disabled`; settings page (authorised, forbidden, saves through the Action, encrypted values stay encrypted); config contract test with the new fields; reset and cart-reminder links use `store.url`.

### R2 — Move the remaining shared services out of Platform (tenancy still on)

- `OnboardingCheck(s)` + `LaunchChecklist` → Settings; checklist page in the store admin.
- `HealthController` → Support; `/health` no longer needs the platform host.
- `secrets:reencrypt` → Support, still scope-aware for now.
- Afterwards no module imports `Modules\Platform\*` except through `TenantDirectory` loops (removed in R3).
- **Tests:** checklist page; health (overall status public, details with token, 503 when a probe fails); secrets rotation still green.

### R3 — The switch to one database

This is the one milestone that cannot be split further. The tenancy context is a global switch, so there is no consistent state in which half the modules are tenant-aware. R1 and R2 move the design work out first, so what remains is mechanical.

1. `config/database.php` → `pgsql` + `pgsql_migrator`; `.env.example`, `phpunit.xml`; `bootstrap-database.sql` and `docker/postgres/01-roles.sql`.
2. Move the 30 migrations out of `tenant/`; `ModuleServiceProvider` loads them; `defaultSeeders()`; `DatabaseSeeder` runs `SeedDefaults`; new queue-tables and drop-column migrations.
3. `MigrateDatabase` + `app:migrate` (one transaction + ledger lockdown).
4. Unregister `PlatformServiceProvider` and `PlatformPanelProvider`; remove the tenancy middleware from `bootstrap/app.php`, the module API group, the admin panel and the Livewire route.
5. Convert every call site in §1.5: search names, jobs, throttles, rate limits, PostHog, staff panel access, reindex/report/import commands, schedule.
6. Test harness: `TestStore` replaces `TestPlatform`; unwrap the about 40 feature test files; delete the isolation and Platform tests marked REMOVE; convert those marked CONVERT.
7. Create local `store` and `store_test` DBs (per D2).

- **Tests:** whole converted suite green; new `MigrateTest` (all-or-nothing, convergence, exit code); `DatabasePrivilegeTest` (app role DML only, append-only enforced); `ScheduleTest`; search tests; pooler config test.

### R4 — Delete tenancy for good, and guard it

- Delete `modules/Platform` (what is left), `config/tenancy.php`, `PlatformPanelProvider`, the brand-switcher view, the `platform` guard, `Support/Queue/Tenant*`, `PermissionCacheBootstrapper`, `TestPlatform`, `WriteMarkerJob`.
- `composer remove stancl/tenancy`; drop the PSR-4 entry and the `test:isolation` script.
- `EncryptedColumn` and `ModuleRegistry` lose scopes; `StaffDirectory` loses hand-off-only methods; `StaffUser` loses the landlord column.
- Horizon and `/docs/api` behind `auth:staff` + `system.monitor` on the admin host.
- **Architecture guard test** (§1.9) added.
- **Tests:** the guard test fails if any forbidden symbol or package is reintroduced (verified by temporarily adding one); Horizon and API-docs access tests; full suite + Larastan + Pint.

### R5 — Per-installation naming

- Disks `media` / `private` + `OBJECT_STORAGE_PREFIX` + `media.disk` data migration (D6).
- `config/platform.php` → `config/store.php`; remaining "tenant"/"brand" wording in module docblocks.
- Docker Compose DB name and MinIO init path.
- **Tests:** media upload and conversion on the renamed disks; private file served only via a signed URL; existing media rows readable after the data migration.

### R6 — Install, backup and deploy tooling

- `store:install` (idempotent: defaults, settings from options or `.env`, owner account with a one-time password, search collection, checklist printout); `store:seed-demo`.
- `db:backup [--drill]` + schedule (D4).
- `deploy/release.sh` → `app:migrate`; `deploy/tests/release.test.sh`; CI workflow (single DB bootstrap, no isolation step, openapi job env).
- **Tests:** `StoreInstallTest` (fresh install, re-run changes nothing, owner created once); `BackupTest` (dump + checksum, drill restores identical, a tampered file is refused); `bash deploy/tests/release.test.sh` 10/10; CI green.

### R7 — Storefront (platform-web)

- Move `app/[site]/**` → `app/**`; remove the site param everywhere; middleware request-id only.
- `api.ts` uses `API_URL`; `siteOrigin()` uses `config.store.url` / `STOREFRONT_URL`; tags unscoped; revalidate without `site` (the API stops sending it in the same milestone).
- Theme from `theme.id` (D3); `.env.example`; Playwright on `localhost`.
- **Tests:** Vitest (theme engine, crypto, client IP, new `siteOrigin` + tags unit tests); typecheck, lint, `next build`; E2E browse, purchase, account and theme specs green against the converted API; revalidation round-trip (API signs, storefront purges the right tag).

### R8 — Documentation

- ADR-0020; supersede 0001/0005/0012/0013; amend 0002/0004/0014/0016/0017/0019; ADR index.
- Master spec v2.0 (§3.2), PROGRESS.md (§3.3), phase-plan and checkpoint banners (§3.4), architecture docs, runbooks, READMEs (§3.5).
- After your confirmation, drop the old local landlord and tenant databases.
- **Tests:** the CI docs job (ADR index complete, no secrets); a grep check that docs outside history banners no longer describe tenancy as current.

### R9 — `CLAUDE.md` in the project root

A root `C:\xampp\htdocs\velvinahome\CLAUDE.md` containing:

- **This is a single-store application.** Multi-tenancy (stancl/tenancy or any tenancy package, a landlord/central DB, tenant/domain/company models, host-based tenant resolution, tenant-prefixed cache/storage/queue/search, a platform panel, impersonation) **must not be re-added**. A second client means a **separate installation** (own DB, own `.env`). The architecture guard test enforces this.
- Store identity comes from admin settings or `.env`, never hardcoded (the table at the top of this plan).
- Which docs are current: the project blueprint (see "Changes made during execution"), ADR-0020 and the non-superseded ADRs, `docs/architecture/*`, `docs/runbooks/*`, PROGRESS.md. Which are history: phase plans/checkpoints before the conversion, ADRs 0001/0005/0012/0013.
- Project structure (`platform-api` modules, `platform-web` apps/packages/themes).
- Main commands: local services (ADR-0009 devtools), `app:migrate`, `store:install`, `store:seed-demo`, `search:reindex`, `db:backup`, Pest groups, Larastan, Pint, pnpm scripts, E2E.
- Conventions: Actions only, module boundaries via Contracts/Events, DTOs, money as integer minor units, ULIDs, append-only ledgers, API v1 additive-only, tests ship with features.
- **Test:** none (documentation); reviewed with you.

---

### Changes made during execution

- `secrets:reencrypt` moved in R3 instead of R2: while tenants existed it had to loop over them,
  which the Support kernel cannot do.
- R3 also deleted the Platform module, the platform panel, the `platform` guard and the tenant
  queue classes (planned for R4): Larastan analyses every module, so dormant Platform code that
  called removed APIs would have failed the R3 gate. Horizon and API-docs access moved with them.
- R3 also switched `deploy/release.sh`, its test, CI and Docker Compose to `app:migrate` and the
  single database (planned for R5/R6), because the commands and SQL they called were removed.
- R7 removed `generateStaticParams` from the category page: static params plus `searchParams`
  returned a 500 on filtered category URLs.
- R8: there is no master spec v2.0. During execution the owner replaced
  `Commerce_Platform_Master_Spec.md` with `Ecommerce_Project_Blueprint.md`, which is already
  single-store, so §3.2 no longer applies. "Spec §N" references in history documents point to
  the old spec. Following blueprint §13.4, `tenant-onboarding.md` became `go-live-checklist.md`
  (not `store-launch-checklist.md`). ADRs 0006/0007/0008/0010 mention tenants in passing, so
  they were marked amended as well. The committed OpenAPI snapshot still had the old
  tenant-host description and `api:contract --check` was failing; it is regenerated.

---

## 6. Risks

| Risk | Mitigation |
|---|---|
| R3 is large (about 100 files touched) | Mechanical by then; done on a branch; the suite is the gate. R1/R2 remove every design decision from it. |
| Losing security properties that came for free from separate DBs (per-tenant roles, CONNECT grants) | The single DB keeps app / migrator / backup roles, revoked PUBLIC CONNECT and append-only revokes, all tested in `DatabasePrivilegeTest`. |
| Two installs sharing Redis or Typesense collide | Per-install `REDIS_PREFIX`, `HORIZON_PREFIX`, `SCOUT_PREFIX` and `OBJECT_STORAGE_PREFIX`, documented in `install-store.md`; `store:install` warns when they are left at defaults. |
| API v1 freeze | Only additive fields and a description text change; `api:contract --check` stays a required CI check. |
| ISR cache mixing during R7 | The storefront accepts revalidation with and without `site` until the API change in the same milestone is deployed. |
| Tenancy creeping back in later | Architecture guard test + CLAUDE.md + ADR-0020. |
