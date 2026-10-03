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
| `APP_KEY` | API `.env` | [A](#a-app_key) — encrypts 2FA secrets, payment/courier/import credentials, encrypted settings, sessions |
| `DB_PASSWORD`, `DB_MIGRATOR_PASSWORD`, backup role | API `.env` | [B](#b-database-roles) |
| `SERVICE_AUTH_SECRET`, `STOREFRONT_REVALIDATE_SECRET` | API **and** storefront env (same value) | [D](#d-secrets-shared-by-api-and-storefront) |
| `STOREFRONT_SESSION_SECRET` | storefront env | [E](#e-storefront-session-secret) |
| `TYPESENSE_API_KEY` | API `.env`, Typesense | [F](#f-third-party-keys) |
| `POSTHOG_PROJECT_KEY` / `NEXT_PUBLIC_POSTHOG_KEY`, `TURNSTILE_SECRET_KEY`, `MAIL_PASSWORD`, `HEALTH_CHECK_TOKEN` | env files | [F](#f-third-party-keys) |
| Payment gateway / courier credentials | the store database, encrypted | Admin: Settings → Payment methods / Couriers (the owner rotates them at the provider, then pastes the new ones) |

## A. `APP_KEY`

Laravel decrypts with `APP_PREVIOUS_KEYS` while everything is moved to the new key.

1. Generate: `php artisan key:generate --show` (prints, changes nothing).
2. In `.env`: `APP_PREVIOUS_KEYS=<current key>` and `APP_KEY=<new key>`. Rebuild config, reload
   (see top). Everything keeps working: old ciphertext, sessions and cookies decrypt with the
   previous key; new writes use the new one.
3. Re-encrypt every stored secret:
   `php artisan secrets:reencrypt`
   It reports, per column, how many values moved and how many were already current. It exits
   non-zero while any value is unreadable — then **stop**: keep the old key and
   investigate the named column (an unreadable value was written under a key that is not configured).
   Re-running is safe.
4. When it reports "The previous key can be retired": wait out the session lifetime (30 min idle,
   12 h absolute for panels; storefront customer tokens are not APP_KEY-encrypted), then remove
   `APP_PREVIOUS_KEYS`, rebuild config, reload.
5. Dumps taken before the rotation (the backup directory) contain values under the old key:
   keep the old key in the secret store, labelled with the date, as long as those dumps are kept
   (`BACKUP_RETENTION`, 14 by default).

## B. Database roles

PostgreSQL holds one password per role, so there is a short window between changing it and
the servers picking it up; open connections are unaffected.

1. Generate a password; put it in the secret store.
2. As the database superuser: `ALTER ROLE store_app WITH PASSWORD '<new>';`
   (or `store_migrator`, `backup_reader`).
3. Immediately update `shared/.env` on every server, rebuild config, reload (top). For PgBouncer
   with `auth_query` nothing else is needed.
4. Check `/health` (database `true`) and run `php artisan app:migrate` (a no-op that proves the
   migrator role) after rotating `store_migrator`.

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
