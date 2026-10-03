# ADR-0002: API host strategy — BFF on the brand domain, plus `api.<brand>`

- **Status:** Accepted; amended by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) — one store per installation, so tenant, brand and landlord passages below are history
- **Date:** 2026-09-26
- **Spec reference:** §4.2 (**YOUR CALL**, ADR required), §6.1, §7.4

## Context

Spec §4.2 requires each tenant to have a storefront domain and an admin domain, and leaves the
API host to engineering judgement: either `api.<domain>` or the same domain via the Next.js
BFF. The choice has to serve four different callers at once:

1. The shopper's browser (needs cookies, CORS-free requests, no exposed secrets).
2. The Next.js server (SSR/ISR rendering, high volume, server-to-server).
3. A future React Native app (no BFF available to it; needs a stable public API host).
4. Payment and courier webhooks (must route to the correct tenant by host, spec §4.4).

## Decision

**Both, with each caller given exactly one correct path.**

| Caller | Host it uses | Notes |
|---|---|---|
| Shopper's browser | `https://<brand-domain>/bff/*` (Next.js route handlers) | First-party cookies, no CORS, no token in JS, no API secret in the bundle |
| Next.js server (SSR/ISR) | `https://api.<brand-domain>/api/v1/*` (or an internal address where the network allows) | Signed service header; no browser involvement |
| Future mobile app | `https://api.<brand-domain>/api/v1/*` | Sanctum token held in the device keystore |
| Webhooks | `https://api.<brand-domain>/api/v1/webhooks/{provider}` | Per-tenant URL, so host-based tenant routing plus signature verification |
| Tenant admin | `https://admin.<brand-domain>` | Filament, session auth, 2FA. Never the API host. |
| Platform panel | the platform's own domain | Landlord panel only |

Rules that make this safe:

- **The browser never calls `api.<brand>` directly** in v1. Every authenticated browser
  request goes through the BFF, so no CORS policy needs to permit browser origins on the API
  host. CORS on `api.<brand>` stays closed by default.
- `api.<brand>` is a `domains` row with `kind = api`, so tenant resolution is uniform: one
  mechanism, one table, for all three host kinds.
- The tenant is resolved **only** from the hostname. The signed service header authenticates
  the Next.js server; it never selects the tenant.
- Every host is behind Cloudflare. WAF rules differ per host kind: bot protection and caching
  on the storefront, stricter rate limits and no caching on the API host, IP/geo restrictions
  plus Turnstile on the admin host.

## Consequences

### Positive

- The browser gets the security properties of same-origin (httpOnly cookies, `SameSite=Lax`,
  no preflight, no token in JS) without hiding the API from the mobile app.
- Webhook URLs are stable, per-tenant and independent of the storefront's rendering host, so
  a storefront redeploy or hosting change never breaks a payment callback.
- SSR traffic bypasses the BFF hop, so the render path stays one network call deep.
- The dedicated topology needs no change: the same three host kinds simply point at a single
  tenant's installation.

### Negative / costs

- Three DNS records and three Cloudflare configurations per tenant instead of one. This is
  scripted as part of the `tenant:create` go-live checklist rather than done by hand.
- Two code paths reach the API (BFF and direct), so both must be covered by tests. Mitigated
  by generating both from the same OpenAPI client.
- TLS certificates for `api.` and `admin.` subdomains per tenant — handled by Cloudflare
  and the Forge/Ploi certificate automation.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Someone adds a browser `fetch` straight to `api.<brand>` | CORS stays closed on the API host; a CI lint rule forbids the API base URL in client-side bundles; CSP `connect-src` on the storefront omits the API host |
| The service header leaks and is replayed | HMAC over method, path, body hash and timestamp; short validity window; replay cache; and it never grants tenant selection |
| Tenant confusion between hosts of the same brand | `domains.kind` selects the middleware stack, so an admin panel cannot be served on a storefront host |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Same domain only, everything proxied through Next.js | The mobile app would have no API host, and webhooks would depend on the storefront deployment being healthy — coupling a payment callback to a frontend deploy. |
| `api.<brand>` only, browser calls it directly with a token in JS | Requires open CORS, exposes the token to XSS, and contradicts the FINAL BFF requirement in spec §6.2. |
| One shared `api.platform.com` with a tenant header | Directly violates spec §4.2: tenant identity would come from user-supplied input. |
| One shared API host with the tenant in the path | Same problem in a different costume, plus it breaks per-tenant WAF rules, rate limits and certificates. |
