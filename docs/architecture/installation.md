# One Installation per Store

The application serves **one store** from **one PostgreSQL database** (ADR-0020). A second
client is a second installation of the same code — its own database, `.env`, deployment,
backups and admin. Multi-tenancy was removed on purpose and must not come back; the
architecture test `tests/Architecture/NoTenancyTest.php` enforces that.

## 1. Where configuration lives

| What | Where | Edited by |
|---|---|---|
| Store name, storefront URL, logo, timezone, locale, country | settings `store.*` | Admin → Settings → Store (`settings.manage`) |
| Currency | setting `store.currency` | `store:install --currency` (fixed once the catalog has prices) |
| Theme id and design-token overrides | settings `theme.*` | Admin → Settings → Store |
| Tax display, SEO, email sender | settings `tax.*`, `seo.*`, `mail.*` | Admin → Settings → Store |
| Feature flags (wishlist, reviews, blog, abandoned cart, …) | settings `features.*` | Admin → Settings → Store |
| Payment method details and merchant credentials | `payment_methods` (credentials encrypted) | Admin → Settings → Payment methods |
| Courier accounts and credentials | `couriers` (credentials encrypted) | Admin → Settings → Couriers |
| Database, Redis, Typesense, object storage, mail transport | `.env` | deployment |
| `APP_KEY`, `SERVICE_AUTH_SECRET`, `STOREFRONT_REVALIDATE_SECRET`, Turnstile, PostHog | `.env` | deployment (secret store) |
| Hosts (`ADMIN_DOMAIN`; storefront's `API_URL`, `STOREFRONT_URL`) | `.env` | deployment |

**Rule:** nothing store-specific is hardcoded. A value the client may change is a setting; a
secret or a piece of infrastructure is `.env`. Settings are published to the storefront by
`GET /api/v1/storefront/config` and cached; saving one purges the storefront's `config` tag.

## 2. Hosts (ADR-0002, amended)

| Host | Serves |
|---|---|
| `www.<domain>` / `<domain>` | the Next.js storefront |
| `api.<domain>` | the Storefront API (`/api/v1`), webhooks, `/health` |
| `admin.<domain>` (`ADMIN_DOMAIN`) | the Filament admin, Horizon and API docs (staff with `system.monitor`) |

With `ADMIN_DOMAIN` empty everything answers on any host (local development).

## 3. Sharing infrastructure between installations

Installations may share a Redis server, a Typesense node, a bucket or a PostgreSQL server.
Each needs its own namespace:

| Shared service | Setting | Example |
|---|---|---|
| Redis (cache, queues, sessions, rate limits) | `REDIS_PREFIX`, `HORIZON_PREFIX` | `velvina:`, `velvina-horizon` |
| Typesense | `SCOUT_PREFIX` → collection `{prefix}products`, synonyms `{prefix}synonyms` | `velvina_` |
| Object storage bucket | `OBJECT_STORAGE_PREFIX` → `<prefix>/media`, `<prefix>/private` | `velvina` |
| PostgreSQL server | database name, role names, passwords | `velvina`, `velvina_app` |

`store:install` warns about an empty `SCOUT_PREFIX` in production.

## 4. Installing a store

See [../runbooks/install-store.md](../runbooks/install-store.md). In short:

```bash
psql -U postgres -f database/setup/bootstrap-database.sql -v app_password=... -v migrator_password=... -v backup_password=...
php artisan store:install --name="Velvina Home" --url=https://velvinahome.com \
    --currency=PKR --timezone=Asia/Karachi --theme=velvina-home --owner-email=owner@example.com
```

`store:install` is idempotent: schema and module defaults (`app:migrate`), identity settings,
the owner account (one-time password shown once), the search collection, then the launch
checklist (also in the admin under Settings → Launch checklist).

## 5. What the code may not do

- No tenancy package, landlord database, tenant/domain/company models or tenant context.
- No hostname-based behaviour in the API or the storefront beyond the configured hosts.
- No per-client branches or forks: per-client differences are settings, a theme package
  (`platform-web/themes/<name>`) or, as a last resort, an extension point (spec §5.1).
