# ADR-0017: Storefront BFF sessions, service authentication and ISR revalidation

- **Status:** Accepted; amended by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) — one store per installation, so tenant, brand and landlord passages below are history
- **Date:** 2026-09-30
- **Spec reference:** §6.2 (FINAL), §6.13, §7.1–§7.5, ADR-0002, ADR-0003

## Context

ADR-0003 fixed the principle (customer tokens never reach browser JavaScript) and left the
mechanics to Phase 5. Building the storefront also raised three problems ADR-0002/0003 did not
answer:

1. The API rate-limits per IP. Every storefront call arrives from the Next.js server's IP, so
   one busy brand would exhaust its own budget, and login audits would record the server.
2. ISR pages need to change within seconds of an admin edit, without purging other brands.
3. One Next.js deployment serves every brand, so every cache key and cookie must be brand-bound.

## Decision

### Sessions: sealed cookies, server actions as the BFF

- The Sanctum token, the guest cart id + cart secret and guest order access tokens live in
  **AES-256-GCM sealed, httpOnly, SameSite=Lax cookies** (`sf_session`, `sf_cart`, `sf_orders`),
  `__Host-` prefixed and `Secure` in production (no Domain, path `/`), keyed by
  `STOREFRONT_SESSION_SECRET`. No server-side session store.
- Mutations are **Next.js server actions** (same-origin POST with Next's action id check); the
  only route handlers are read-only (`/bff/session`, `/bff/search/suggest`). There is no generic
  proxy route.
- A cookie is expired **with the attributes it was set with** (`Secure`, path `/`, `maxAge: 0`).
  A bare delete is ignored by browsers for `__Host-` cookies — found in Phase 5 E2E, where
  sign-out left the customer signed in on the production build only.
- Logout revokes the token at the API first. Signing in merges the guest cart into the account.

### Service authentication (Next.js → API), `SERVICE_AUTH_SECRET`

- **Rendering** calls carry `X-Storefront-Render: hmac("storefront-render:v1")`. Constant per
  deployment so the fetch stays cacheable; such requests skip the per-IP catalog/search limits.
- **Customer** calls carry `X-Storefront-Client-Ip` and `X-Storefront-Signature:
  v1,<ts>,hmac("<ts>\n<ip>")` (60 s window). The API then treats the customer IP as the request
  IP for rate limits, login audits and logs.
- Neither header ever selects the brand; the host does (ADR-0002). When the client IP is
  applied, the host and scheme a trusted proxy forwarded are resolved **first** — otherwise the
  request would stop coming "from" the proxy and lose its brand (a bug found in Phase 5).
- Invalid or missing signatures are ignored, never rejected: the request is an ordinary one.
- **Deployment requirement:** the storefront takes the customer IP from `X-Forwarded-For`, so it
  must sit behind a proxy/CDN that overwrites that header. Exposed directly, a client could
  choose its own rate-limit bucket.

### Rendering and revalidation

- Middleware maps the host to a brand (unknown → 404), rewrites to `/[site]/…`; every fetch is
  tagged `<brand>|<tag>` (`products`, `product:<slug>`, `categories`, `pages`, `menus`,
  `config`, `reviews:<ulid>`…). Catalog and content pages are ISR (empty
  `generateStaticParams`, rendered on first request); **cart, checkout, orders and every account
  page declare `dynamic = "force-dynamic"` themselves** — a parent layout's setting is not
  enough once the brand layout is statically generated.
- Model observers in the API collect tags per unit of work and record one
  `storefront.revalidate` **outbox** message after commit. The handler POSTs `{site, tags,
  message}` to the storefront's `/api/revalidate`, signed with `STOREFRONT_REVALIDATE_SECRET`
  over `<ts>\n<body>` (5-minute window). The storefront accepts only allow-listed tag shapes and
  scopes them to the signed brand, so one brand's edit can never purge another brand.
- Measured locally: an admin rename is live on the product page about 6 s later.

## Consequences

### Positive

- No token, cart secret or order token is readable by page scripts (E2E asserts httpOnly).
- Per-customer rate limits and audits work through the BFF; page rendering is not throttled.
- Stateless sessions: no Redis dependency in the storefront.
- Revalidation inherits the outbox's retries and idempotency (purging twice is harmless).

### Negative / costs

- Sealed cookies are limited to ~4 KB; guest order tokens are capped at the last 10 orders.
- Rotating `STOREFRONT_SESSION_SECRET` signs every customer out (acceptable; documented).
- Three shared secrets must be kept equal across the two deployments.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Per-user page cached by ISR and shown to another customer | Explicit `force-dynamic` on each per-customer page; E2E registers two customers and checks each sees only their own account, and `Cache-Control: private/no-store` |
| Spoofed `X-Forwarded-For` | Proxy must overwrite it (deployment requirement above); the API still limits per account |
| Replay of a signed customer call | 60 s window; the signature binds the IP only, so a replay gains nothing beyond that IP's own budget |
| Forged revalidation | HMAC over timestamp + body, 5-minute window, allow-listed tags, brand-scoped |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Server-side session store (Redis) keyed by an opaque cookie | Another stateful dependency for the storefront; sealed cookies give the same XSS protection |
| `/bff/*` route handlers for every mutation | Server actions give the same-origin guarantees with less code and typed inputs |
| Trusting `X-Forwarded-For` at the API from the storefront's IP | Needs the storefront's egress IPs in the API's trusted proxies and still lets any trusted hop set it; the signed header proves the storefront sent it |
| Time-based revalidation only | Minutes of stale prices and stock after an edit |
