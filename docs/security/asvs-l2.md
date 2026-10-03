# Security review — OWASP ASVS 4.0.3 Level 2

Phase 7, milestone 7.3 (spec §8: "Use OWASP ASVS Level 2 as the checklist"). Reviewed
2026-10-01 against `platform-api` and `platform-web` at the Phase 7 commits. Rows touched by the
single-store change (ADR-0020, 2026-10-03) are updated in place and marked *(ADR-0020)*.

Each chapter lists **evidence** (where the control lives and which test proves it), what was
**fixed** in this review, and what remains **open** with an owner. "Hosting" items wait for the
owner's hosting decision (Phase 7 plan) and are tracked in the deploy runbook.

Legend: ✅ met · 🔧 fixed in this review · ⏳ open (reason given)

## V1 Architecture

| Control | State | Evidence |
|---|---|---|
| Documented trust boundaries, components, data flows | ✅ | `docs/architecture/*`, ADR index (ADR-0020 current); BFF pattern ADR-0003 |
| Store data separation as an architectural control *(ADR-0020)* | ✅ | One store per installation, each with its own database, roles, `.env` and key prefixes (`installation.md`); `tests/Architecture/NoTenancyTest.php` keeps shared-installation code out |
| Central, enforced conventions | ✅ | Architecture tests `tests/Architecture/*`: Actions only write, no cross-module internals, no tenancy, no floats in money |
| Least-privilege components | ✅ | Runtime role `store_app` has DML only; migrations use `store_migrator`; backups `backup_reader` (`DatabasePrivilegeTest`) |

## V2 Authentication

| Control | State | Evidence |
|---|---|---|
| Password length ≥ 10, letters + numbers, breached-password check (one policy for staff and customers) | ✅ | `Identity/Support/PasswordPolicy.php` (HIBP k-anonymity, `PASSWORD_BREACH_CHECK`) |
| Strong hashing | ✅ | Argon2id (`CustomerAuthTest` "hashes customer passwords with Argon2id") |
| Brute force: throttling and lockout | ✅ | `throttle:auth` 5/min per IP **and** per account; `LoginThrottle` lockout (staff 5 attempts / 15 min); tests `CustomerAuthTest`, `StaffAuthenticationTest` |
| Same response for unknown account and wrong password | ✅ | `CustomerAuthTest` "gives the same answer…" |
| MFA for privileged users | ✅ | Filament app-based TOTP, mandatory for staff and platform users (`TwoFactorTest`) |
| Authentication events logged | ✅ | `login_audits` (customers, staff), platform audit (`AuditStaffAuthentication`, platform sign-in tests) |
| Automated-attack defence on public auth forms (spec: Turnstile) | 🔧 | **New** `Support/Http/VerifyHumanChallenge` (alias `human`) on register, login, forgot-password and order placement; storefront `HumanCheck` widget; `HumanChallengeTest`; verified end-to-end against Cloudflare with its test keys. ⏳ Off until `TURNSTILE_SECRET_KEY` / `NEXT_PUBLIC_TURNSTILE_SITE_KEY` are set (needs the Cloudflare account — hosting) |
| Reset tokens: random, hashed at rest, short-lived, single-use, sessions revoked | ✅ | `RequestCustomerPasswordReset` / `ResetCustomerPassword` (sha256, expiry, all tokens revoked) |

Accepted: the **staff admin login** has no Turnstile; it is protected by mandatory TOTP,
per-account lockout and audit, and is not a public sign-up surface.

## V3 Session management

| Control | State | Evidence |
|---|---|---|
| Customer tokens never reach browser JS | ✅ | BFF: Sanctum token in sealed, httpOnly, `__Host-`, SameSite=Lax cookie (`lib/session.ts`) |
| Token expiry and revocation | ✅ | 30-day Sanctum tokens; revoked on logout, password change (other devices) and reset |
| Panel session cookies Secure/HttpOnly/SameSite, encrypted session | ✅ | `config/session.php` (secure outside local/testing, `SESSION_ENCRYPT=true`), Redis store |
| Session ID rotated on login, invalidated on logout | ✅ | Laravel/Filament defaults; `AuthenticateSession` on both panels |
| Re-authentication: ≤ 30 min idle, ≤ 12 h absolute (3.3.2) | 🔧 | Idle default lowered 120 → **30 min** (`SESSION_LIFETIME`); **new** `Support/Http/EnforceSessionAge` ends panel sessions after `PANEL_MAX_SESSION_HOURS` (12); `SessionAgeTest` |
| Impersonation time-boxed | ✅ | `EnforceImpersonationExpiry` |

## V4 Access control

| Control | State | Evidence |
|---|---|---|
| Deny by default; checks enforced server-side inside Actions, not only in UI | ✅ | Policies + permission checks in Actions (`StaffAuthenticationTest` "enforces permissions inside the Action even when the UI is bypassed") |
| IDOR: customer data scoped to the customer; unguessable ids | ✅ | ULIDs in URLs; guest orders need `X-Order-Token` (HMAC); cart secret per guest cart |
| One installation cannot reach another *(ADR-0020)* | ✅ | Separate databases, roles, credentials and Redis/Horizon/Scout/storage prefixes per installation (`installation.md`) |
| Operations tooling restricted *(ADR-0020)* | ✅ | Horizon and API docs need `system.monitor` (Owner only; `StaffAdminTest`) |

## V5 Validation, sanitisation, encoding

| Control | State | Evidence |
|---|---|---|
| SQL injection | ✅ | Query builder bindings everywhere. Reviewed every raw expression: `DatabaseSalesLedger` (constant SQL), migration lockdown DDL (constant table names from the module registry, always quoted), restore drill (configured database name, quoted) |
| Mass assignment | ✅ | Validated DTOs (spark/laravel-data) into Actions; architecture test forbids `$request->all()` |
| XSS | ✅ | React escaping; the only `dangerouslySetInnerHTML` uses are JSON-LD (`<` escaped) and theme CSS (`<` stripped, tokens validated). Article bodies are rendered as React elements, never as HTML. Emails escape with `e()` |
| URLs typed by staff | ✅ | `Sections::LINK` rule (http/https/relative only) |
| Open redirect after sign-in | ✅ | `safeNext()` in `actions/account.ts` (same-site relative paths only) |
| Price/quantity tampering | ✅ | Totals recomputed server-side; order placed against `expected_total` (409 `price_changed`); idempotency keys with body fingerprint |

## V6 Stored cryptography

| Control | State | Evidence |
|---|---|---|
| Secrets encrypted at rest | ✅ | Payment/courier/import credentials, 2FA secrets and secret settings (`encrypted` casts, "stores encrypted settings as unreadable ciphertext") |
| Approved algorithms, no custom crypto | ✅ | Laravel encrypter (AES-256-GCM/CBC+HMAC), HMAC-SHA256 for signatures, Web Crypto AES-GCM for BFF cookies (`lib/crypto.ts` + tests) |
| Random values from CSPRNG | ✅ | `Str::random`/`random_bytes`, `crypto.randomUUID()` |
| Key and credential rotation without data loss (6.4.1/6.4.2) | 🔧 | **New** `secrets:reencrypt` moves every stored secret (declared per module, completeness enforced by test) to a new `APP_KEY`; database role passwords rotated per runbook *(ADR-0020)*; `SecretsRotationTest`; runbook `rotate-secrets.md` |

## V7 Errors and logging

| Control | State | Evidence |
|---|---|---|
| No stack traces or debug output in responses | ✅ | `ApiErrorRenderer` envelope; `SecurityHeadersTest` "no detail leak when debug is off" |
| Security events logged with request id; no secrets/PII in error reports | ✅ | Request id propagated storefront → API; `PostHogErrors` scrubs emails, long numbers, SQL (ADR-0019, `MonitoringTest`) |
| Audit trails cannot be altered by the app | ✅ | Append-only ledgers / audit tables locked by grants (`MigrateDatabase::lockDownLedgers`, re-applied on every `app:migrate`; `DatabasePrivilegeTest`) |
| Log retention and central log store | ⏳ | Hosting decision |

## V8 Data protection

| Control | State | Evidence |
|---|---|---|
| Sensitive data not cached publicly | ✅ | Per-customer API calls `no-store`; checkout/account pages `force-dynamic`, `noindex` |
| Minimal PII in analytics | ✅ | PostHog: no person profiles, no session recording, query strings stripped |
| Backups restorable and practised | 🔧 | `db:backup` nightly, weekly automated `db:backup --drill` with checksum and row-count comparison, `restore-database.md`, `BackupTest` *(ADR-0020)* |

## V9 Communications

| Control | State | Evidence |
|---|---|---|
| HSTS, TLS everywhere, TLS to PostgreSQL | ⏳ | HSTS sent over HTTPS (`SecurityHeaders`); TLS termination and `sslmode=require` are configured at deployment (hosting) |
| Outbound TLS verified | ✅ | Default HTTP client verification (CA bundle configured on the dev PHP too) |

## V10 Malicious code / supply chain

| Control | State | Evidence |
|---|---|---|
| Dependency audit, lockfiles, automated updates | ✅ | `composer audit` and `pnpm audit` clean (7.2); Dependabot in both repos; lockfiles committed |
| Subresource / third-party scripts limited | ✅ | CSP allows only self + PostHog assets + Turnstile, each only when configured |

## V11 Business logic

| Control | State | Evidence |
|---|---|---|
| Anti-automation on costly flows | ✅ 🔧 | `throttle:checkout` (per client + per IP), Turnstile when configured, idempotent placement |
| Rate limits use the real client IP | 🔧 | API side: HMAC-signed client IP from the BFF (`TrustStorefrontService`). **Fixed** storefront side: `lib/client-ip.ts` no longer trusts the client-supplied left-most `X-Forwarded-For` entry; uses `TRUSTED_PROXY_HOPS` from the right, or one edge header (`CLIENT_IP_HEADER`, e.g. `cf-connecting-ip`); unit tests |
| Stock and payment state transitions enforced | ✅ | Inventory ledger with reservations, concurrency tests; order state machine |

## V12 Files and resources

| Control | State | Evidence |
|---|---|---|
| Upload type and size limits, content re-encoded | ✅ | Product images decoded and re-encoded to WebP (EXIF removed); payment proofs limited to JPEG/PNG/WebP/PDF ≤ 5 MB |
| Uploaded files not executable / served from storage disk | ✅ | Media disk outside the web root, served through generated URLs |
| SSRF on server-side fetches | ✅ | `Imports/Support/RemoteUrl` resolves and refuses private/reserved addresses |

## V13 API and web services

| Control | State | Evidence |
|---|---|---|
| Versioned, documented, contract-checked API | ✅ | `/api/v1`, Scramble OpenAPI, `api:contract --check` |
| Webhooks: signature verified before storage, replay-safe | ✅ | `ReceiveWebhook`: per-gateway `verifyWebhook`, unique provider event id. ⏳ Timestamp windows are part of each gateway adapter (JazzCash/Easypaisa deferred by the owner) |
| Backend → storefront calls signed | ✅ | ISR revalidation HMAC with timestamp (`RevalidateStorefront`) |

## V14 Configuration

| Control | State | Evidence |
|---|---|---|
| Security headers on API, panels, storefront | ✅ | `SecurityHeaders` middleware (`SecurityHeadersTest`), `next.config.ts`; storefront now also sends `frame-src 'none'` unless Turnstile is on |
| No secrets in the repositories | ✅ | `.env.example` placeholders only; keys only in local env files (checked with `git grep`) |
| Database privileges | ✅ | No provisioner role any more *(ADR-0020)*: the drill empties a scratch database it owns (`DROP SCHEMA public CASCADE`) instead of dropping databases |
| Production build without debug, cached config | ✅ ⏳ | `deploy/release.sh` caches config/routes/events/views and bakes the release id; `deploy.md` sets `APP_DEBUG=false`. First real run on staging (hosting) |
| Store identity not taken from request input *(ADR-0020)* | ✅ | No host-based resolution; store name, URL and theme come from settings, secrets from `.env` |

## Open items (owners)

| Item | Needs | Where tracked |
|---|---|---|
| Turnstile keys, Cloudflare WAF, HSTS preload, TLS termination | Cloudflare account + hosting | Phase 7 plan, deploy runbook |
| TLS to PostgreSQL, encryption at rest, off-site backups / PITR | Hosting | `restore-database.md`, `database-roles.md` |
| Central log retention | Hosting | `incident-response.md` |
| Webhook timestamp windows per gateway | Payment adapters (deferred by owner) | ADR-0018 |
