# Runbook: rotate secrets

Every secret, where it lives, and how to replace it without downtime (spec §8, ADR-0003). Rotate
on a schedule (yearly at least), whenever someone with access leaves, and **at once** on any
suspicion of exposure. Secrets live only in the secret store and the servers' `shared/.env`
files — never in git (CI checks).

After changing anything in `.env`, the cached config must be rebuilt: run a release
(`deploy.md`), or on the server `cd current && php artisan config:cache`, then reload PHP-FPM and
`php artisan horizon:terminate`.

## Inventory

| Secret | Where | Procedure |
|---|---|---|
| `APP_KEY` | API `.env` | [A](#a-app_key) — encrypts tenant DB passwords, 2FA secrets, payment/courier/import credentials, encrypted settings, sessions |
| Tenant DB role passwords (2 per brand) | landlord `tenant_databases`, encrypted | [B](#b-a-brands-database-passwords) |
| `DB_LANDLORD_PASSWORD`, `DB_LANDLORD_MIGRATOR_PASSWORD`, `DB_PROVISIONER_PASSWORD`, backup role | API `.env` | [C](#c-platform-database-roles) |
| `SERVICE_AUTH_SECRET`, `STOREFRONT_REVALIDATE_SECRET` | API **and** storefront env (same value) | [D](#d-secrets-shared-by-api-and-storefront) |
| `STOREFRONT_SESSION_SECRET` | storefront env | [E](#e-storefront-session-secret) |
| `TYPESENSE_API_KEY` | API `.env`, Typesense | [F](#f-third-party-keys) |
| `POSTHOG_PROJECT_KEY` / `NEXT_PUBLIC_POSTHOG_KEY`, `TURNSTILE_SECRET_KEY`, `MAIL_PASSWORD`, `HEALTH_CHECK_TOKEN` | env files | [F](#f-third-party-keys) |
| Payment gateway / courier credentials | each brand's database, encrypted | Brand admin: Settings → Payments / Couriers (the brand owner rotates them at the provider, then pastes the new ones) |

## A. `APP_KEY`

Laravel decrypts with `APP_PREVIOUS_KEYS` while everything is moved to the new key.

1. Generate: `php artisan key:generate --show` (prints, changes nothing).
2. In `.env`: `APP_PREVIOUS_KEYS=<current key>` and `APP_KEY=<new key>`. Rebuild config, reload
   (see top). Everything keeps working: old ciphertext, sessions and cookies decrypt with the
   previous key; new writes use the new one.
3. Re-encrypt every stored secret, landlord and all brands:
   `php artisan secrets:reencrypt`
   It reports, per database, how many values moved and how many were already current. It exits
   non-zero while any brand failed or any value is unreadable — then **stop**: keep the old key and
   investigate the named column (an unreadable value was written under a key that is not configured).
   Re-running is safe.
4. When it reports "The previous key can be retired": wait out the session lifetime (30 min idle,
   12 h absolute for panels; storefront customer tokens are not APP_KEY-encrypted), then remove
   `APP_PREVIOUS_KEYS`, rebuild config, reload.
5. Exports taken before the rotation (`storage/app/exports`) contain values under the old key:
   keep the old key in the secret store, labelled with the date, as long as those exports are kept
   (`TENANT_EXPORT_RETENTION`, 14 by default).

## B. A brand's database passwords

`php artisan tenant:rotate-db-passwords <slug>`

Both roles of the brand get new random passwords; the landlord record is committed only after
PostgreSQL accepted both, and the cached host resolution is dropped. Open sessions keep working,
new connections use the new passwords. If it fails half-way, run it again. PgBouncer must
authenticate with `auth_query` (not a static `userlist.txt`), so it picks up new passwords by
itself. Audit: `tenant.db_passwords_rotated`.

To rotate every brand, run it once per slug (the slugs are in the platform panel's tenant list,
or `select slug from tenants where status <> 'archived'` in the landlord).

## C. Platform database roles

PostgreSQL holds one password per role, so there is a short window between changing it and
the servers picking it up; open connections are unaffected.

1. Generate a password; put it in the secret store.
2. As the database superuser: `ALTER ROLE landlord_app WITH PASSWORD '<new>';`
   (or `landlord_migrator`, `platform_provisioner`, the backup role).
3. Immediately update `shared/.env` on every server, rebuild config, reload (top). For PgBouncer
   with `auth_query` nothing else is needed.
4. Check `/health` (database `true`) and run `php artisan tenants:migrate` (a no-op that proves the
   migrator role) after rotating `landlord_migrator`.

## D. Secrets shared by API and storefront

`SERVICE_AUTH_SECRET` (signed BFF → API calls) and `STOREFRONT_REVALIDATE_SECRET` (API → storefront
revalidation) must match on both sides. There is no dual-key window, so during the switch some
requests fall back to unsigned behaviour: shopper rate limits apply per storefront server IP for
a moment, and a revalidation may be retried by the outbox (it retries with backoff).

1. Generate (`openssl rand -hex 32`).
2. Update the storefront env and the API `.env` together; deploy/reload both within a minute,
   storefront first.
3. Check: a product edit in the admin reaches the storefront (revalidation), and the API log
   shows customer IPs, not the storefront server's.

## E. Storefront session secret

`STOREFRONT_SESSION_SECRET` seals the session cookie. Changing it signs every customer out (their
carts survive: guest carts are found again by token). Do it at a quiet hour: update the storefront
env, redeploy.

## F. Third-party keys

Create the new key at the provider (Typesense: `POST /keys`; PostHog: project settings; Cloudflare
Turnstile: widget settings; mail provider), put it in `.env`, rebuild config and reload, verify,
then revoke the old key at the provider. `HEALTH_CHECK_TOKEN`: update the uptime monitor first,
then the API.
