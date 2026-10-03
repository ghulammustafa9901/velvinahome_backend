# Phase 5 Plan — Next.js Storefront

> **Historical record. Superseded in part by [remove-multi-tenancy.md](remove-multi-tenancy.md) and
> [ADR-0020](../adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Goal (spec §13):** monorepo setup; api-client; design system and default theme; tenant
resolution and config loading; commerce core components; page-builder section rendering;
listing, product, search, cart, checkout, account; BFF authentication; SEO layer; ISR and
backend-triggered revalidation; accessibility and performance passes; first tenant theme.

**Exit criteria:** default theme and one tenant theme on separate local domains from the same
codebase; full purchase flow end to end; Core Web Vitals targets in lab tests; Playwright E2E
passes.

## Architecture (platform-web)

| Package | Role |
|---|---|
| `apps/storefront` | Next.js 15 (App Router). Middleware resolves the brand from the hostname and rewrites to `/[site]/…`, so ISR caches per brand. BFF: server actions + a few typed route handlers; the customer token and guest cart secret live in encrypted httpOnly cookies. |
| `packages/api-client` | Generated typed client (Phase 4). |
| `packages/theme-engine` | Theme registry, tokens → CSS variables, component variants. |
| `packages/ui` | Primitives (button, input, field, badge…), token-driven. |
| `packages/commerce-core` | Product card, price, gallery, variant selector, add-to-cart, cart, checkout form, account views, search box, listing filters, reviews, breadcrumbs. Presentation + client logic only; server work is passed in as actions by the app. |
| `packages/sections` | Section registry for page-builder JSON; unknown sections render nothing. |
| `themes/default`, `themes/velvina-home` | Complete default theme; first tenant theme (editorial). |

### API access

Server-only. Production: `https://api.<brand host>`. Local/internal: `API_INTERNAL_URL`
(e.g. `http://127.0.0.1:8000`) with `X-Forwarded-Host: api.<brand host>` (the API trusts the
loopback proxy). Every call carries a **signed service header** (HMAC over timestamp, client
IP, method and path) so the API applies per-customer rate limits to the real client IP — the
tenant is still chosen only by the host.

### Rendering

Catalog, category, product and content pages: Server Components with ISR (`revalidate` +
tags per brand). Cart, checkout and account: dynamic, `no-store`. Images use the API's
responsive WebP sizes directly (`srcset`, explicit dimensions, lazy below the fold).

### Backend additions

- Service-header verification middleware (client IP for rate limiting).
- Revalidation: product/category/collection/page/menu/settings changes record an outbox
  message; a handler POSTs signed tag lists to the brand's storefront `/api/revalidate`.

## Tests

Unit (Vitest) for crypto/session, section registry, theme CSS; Playwright E2E against the dev
servers: browse → search → product → cart → COD checkout → confirmation; account register /
login / orders; theme per brand; 404 for unknown host. Lighthouse lab run on home, category,
product.
