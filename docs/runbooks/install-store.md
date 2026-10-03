# Runbook: install a store

One installation serves one client's store (ADR-0020). This sets a new installation up on a
server, or a second client's store on separate infrastructure. Takes about an hour plus DNS.

## 1. Infrastructure

- PostgreSQL 17 (with PgBouncer in transaction mode), Redis, Typesense 30, S3-compatible
  object storage (Cloudflare R2), a mail transport, PHP 8.4 with `pdo_pgsql`, Node 22 for the
  storefront.
- Sharing a Redis, Typesense, bucket or PostgreSQL server with another installation is fine
  with distinct prefixes and names (step 3); see [../architecture/installation.md](../architecture/installation.md) §3.

## 2. Database

As a PostgreSQL superuser, once per environment (passwords from the secret store):

```bash
psql -U postgres -f database/setup/bootstrap-database.sql -v db=store \
     -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
# scratch database for the weekly restore drill
psql -U postgres -f database/setup/bootstrap-database.sql -v db=store_restore_drill \
     -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
```

For a second installation on the same server, use another database name (and, if you prefer,
edit the role names in a copy of the script).

## 3. `.env` (API)

From `.env.example`: `APP_KEY` (`php artisan key:generate`), `DB_*` and `DB_MIGRATOR_*`,
`DB_POOLER_HOST`, Redis, Typesense, `R2_*`, mail transport, `SERVICE_AUTH_SECRET`,
`STOREFRONT_REVALIDATE_SECRET`, `ADMIN_DOMAIN`, `STOREFRONT_URL`, `HEALTH_CHECK_TOKEN`,
PostHog and Turnstile keys. On shared infrastructure set **`REDIS_PREFIX`, `HORIZON_PREFIX`,
`SCOUT_PREFIX` and `OBJECT_STORAGE_PREFIX`** to values unique to this installation.

## 4. Install

```bash
php artisan store:install \
  --name="Velvina Home" --url=https://velvinahome.com \
  --currency=PKR --timezone=Asia/Karachi --locale=en --country=PK --theme=velvina-home \
  --owner-email=owner@example.com --owner-name="Owner Name"
```

It runs `app:migrate` (schema + module defaults), writes the store identity, creates the owner
account and prints its **one-time password** (shown once — hand it over securely; the owner
enrols 2FA at first sign-in), creates the search collection and prints the launch checklist.
It is idempotent: re-run it to fill in what is missing; options left out change nothing.
The currency is fixed once the catalog has prices.

## 5. Storefront (`platform-web`)

`.env`: `API_URL` (internal API address), `STOREFRONT_URL`, `STOREFRONT_SESSION_SECRET`,
`SERVICE_AUTH_SECRET` and `STOREFRONT_REVALIDATE_SECRET` (same values as the API), PostHog and
Turnstile public keys. Build with the API reachable (`pnpm build`), then run `pnpm start` behind
the proxy. A client-specific theme is a package under `themes/` registered in
`apps/storefront/src/lib/themes.ts`; the store selects it with its `theme.id` setting.

## 6. DNS, TLS, edge

`<domain>` → storefront, `api.<domain>` → API, `admin.<domain>` → API (admin). Cloudflare in
front with the WAF rules of ADR-0002. Set the uptime monitor on `https://api.<domain>/health`.

## 7. Go live

Work through the launch checklist (admin → Settings → Launch checklist, or the end of
`store:install`): storefront URL, logo, sender email, products, delivery and payment methods,
home page, a test order. See [../go-live-checklist.md](../go-live-checklist.md).
Cron runs `php artisan schedule:run` every minute; a supervisor keeps Horizon running.
