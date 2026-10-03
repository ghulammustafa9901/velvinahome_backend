# ADR-0003: Customer authentication through a Next.js BFF

- **Status:** Accepted; amended by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) — one store per installation, so tenant, brand and landlord passages below are history
- **Date:** 2026-09-26
- **Spec reference:** §6.2 (FINAL), §7.4, §8

## Context

Three separate user populations exist and must never mix (FINAL, spec §6.2): platform users
(landlord DB), tenant staff (tenant DB), customers (tenant DB). The storefront must
authenticate customers without any token reaching browser JavaScript, support guest checkout,
and allow an account to be claimed after purchase.

## Decision

### Customers — Sanctum tokens held server-side by the BFF

```text
browser                    Next.js route handler              platform-api
  POST /bff/auth/login  ─▶  POST /api/v1/auth/login       ─▶  Sanctum issues token
  (email, password)         (signed service header)            │
                            ◀────────── token ──────────────────┘
  ◀── Set-Cookie: session  (httpOnly, Secure, SameSite=Lax, brand domain, no token in JS)
  GET /bff/account/orders ─▶ reads the cookie, attaches the
                             token as a Bearer header  ─────▶ GET /api/v1/account/orders
```

- The Sanctum token is stored **encrypted inside the session cookie payload**, or in a
  server-side session store keyed by an opaque cookie id — implementation detail decided in
  Phase 5, but in neither case is the token readable by browser JavaScript.
- Cookie attributes: `httpOnly`, `Secure`, `SameSite=Lax`, scoped to the brand domain, with a
  sliding expiry.
- Token abilities are scoped per token; a customer token can never reach an admin route
  because customers use a separate guard entirely.
- **Guest checkout** carries an opaque `cart_token` cookie with the same attributes. After a
  guest purchase, the order's email can be claimed: the customer sets a password and the
  guest orders are linked to the new customer record inside one transaction.
- Logout revokes the token server-side (not merely clearing the cookie), so a stolen cookie
  dies with the session.

### Staff — Filament session auth, mandatory 2FA

Session cookies scoped to `admin.<brand>`, **mandatory 2FA** (TOTP, recovery codes
encrypted), idle session timeout, Turnstile on the login form, login audit rows for both
success and failure (spec §6.2). Staff never obtain an API token in v1.

### Platform users — the same, on the platform domain

Separate guard, landlord DB, mandatory 2FA, and every impersonation recorded in
`impersonation_sessions` with a mandatory reason and an expiry.

### Passwords

Argon2id (bcrypt only if Argon2id is unavailable on the host), a breached-password check at
registration and password change, throttling plus lockout per IP and per account.

## Consequences

### Positive

- An XSS on the storefront cannot exfiltrate a session token, because the token was never in
  the DOM, in `localStorage`, or in a JS variable. This is the single largest security win
  available on the storefront.
- No API secret or base URL for privileged calls ships in the client bundle.
- CSRF is handled structurally: `SameSite=Lax` plus BFF routes that accept only same-origin
  requests, plus Laravel's own CSRF protection for Filament.
- The mobile app can use the same `/api/v1/auth/login` endpoint with a device-keystore token,
  with no server changes.

### Negative / costs

- Every authenticated storefront request takes an extra hop (browser → Next.js → API). Public
  catalog and content pages are unaffected, because those render server-side and are
  cached/ISR — the hop only applies to cart, checkout and account, which are uncacheable
  anyway.
- The Next.js layer becomes security-relevant: its session secret must be managed like any
  other production secret and rotated on a schedule.
- Server-side session state (if the session-store variant is chosen) needs Redis, which the
  platform already runs.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Session fixation / cookie theft | Rotate the cookie id on login and on privilege change; revoke server-side on logout; short sliding expiry |
| BFF route used as an open proxy to arbitrary API paths | BFF routes are an explicit allowlist of typed handlers; no generic `/bff/[...path]` catch-all |
| Next.js session secret leaks | Stored in the secret manager, never in the repo, rotated on a schedule, rotation documented in a runbook |
| A developer adds `localStorage.setItem('token', …)` | ESLint rule forbidding token-shaped writes to web storage; code review; the API client has no token parameter on the client side |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Token in `localStorage` | Any XSS is a full account takeover. Contradicts a FINAL requirement. |
| Sanctum SPA cookie mode (stateful, `withCredentials`) | Requires the storefront and API to be same-site and CSRF-token dance in the browser; conflicts with the ISR/Server-Component rendering model and adds nothing over the BFF. |
| Shared session across brands of one company | Would require cross-database session state, which §4.1a forbids. Brand switching uses a short-lived single-use signed hand-off token instead. |
| JWT with a refresh token in JS | Same XSS exposure, plus revocation becomes hard. Sanctum tokens are revocable server-side today. |
