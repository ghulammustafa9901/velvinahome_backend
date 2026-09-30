# Phase 5 Checkpoint — Next.js Storefront

**Date:** 2026-09-30 · **Status:** built
Plan: [plans/phase-5.md](plans/phase-5.md) · Decisions: [ADR-0017](adr/0017-storefront-bff-sessions-service-auth-and-revalidation.md)
Code: `platform-web` (`apps/storefront`, `packages/*`, `themes/*`)

## 1. Exit criteria

| Criterion | Result |
|---|---|
| Default theme + one tenant theme on separate local domains | **Met.** `alpha.localhost` renders the default theme, `bravo.localhost` the **velvina-home** theme (own fonts, palette, centered header, editorial cards, full-bleed hero). Unknown hosts are a 404; unknown paths get the brand's own 404. |
| Full purchase flow works end to end | **Met.** Browse → search → PDP → add to cart → checkout → confirmation, with **COD** (alpha) and **manual bank transfer** (bravo); signed-in checkout with the email prefilled; guest cart merged at sign-in. |
| Core Web Vitals within target in lab tests | **Met with a caveat.** Production build, emulated mobile over 4G, home / listing / product on both brands: **LCP 0.67–0.72 s, CLS 0.000, TBT 99–116 ms** at a 2× CPU slowdown. At Lighthouse's 4× preset, TBT is 480–850 ms, but that preset assumes a fast desktop CPU; this machine is a 2016 dual-core laptop (i5-6300U). LCP stays under 1.7 s and CLS at 0 even at 4×. The budget must be re-measured on the CI runner. |
| Playwright E2E passes | **Met.** 22 tests (desktop + mobile) on the production build. |

**Test suites:** platform-api 300 passing (1 skipped), Larastan level 8 and Pint clean,
`api:contract --check` passes. platform-web: lint (ESLint, 0 warnings), strict typecheck,
Vitest (theme engine, cookie sealing), `next build`, Playwright E2E.

## 2. What was built

| Area | Result |
|---|---|
| Monorepo | pnpm workspace: `apps/storefront`, `packages/{api-client, ui, commerce-core, sections, theme-engine}`, `themes/{default, velvina-home}` |
| Tenant resolution | Middleware: host → brand, config fetched from the API and cached; `/[site]/…` rewrite; optional pinned host for dedicated deployments |
| Theme engine | Token sets (colour, type, radius, spacing) validated against a safe-value pattern, emitted as CSS variables; variants per component (header, product card, hero); tenant config may override tokens |
| Commerce core | Price, product image (API `srcset`), product card/grid, gallery, variant picker + add to cart, cart, totals, one-page checkout (server quotes only, idempotency key per visit, `price_changed` handled), search combobox, facets/sort/cursor pagination, reviews, account order list/detail |
| Sections | hero, rich text, product grid, category grid, banner, FAQ, newsletter; unknown types skipped |
| Pages | Home, pages, product listing, category, collection, PDP, search, cart, checkout, order confirmation (guest and signed in, transfer receipt upload), account (orders, addresses, profile, wishlist), sign in/up, password recovery |
| BFF | Sealed httpOnly cookies, server actions, signed service calls (ADR-0017) |
| SEO | `generateMetadata` everywhere, canonical URLs, Open Graph, JSON-LD (Product/Offer/AggregateRating, BreadcrumbList, Organization, WebSite + SearchAction), chunked sitemap, per-brand robots.txt, filtered and search URLs `noindex` |
| ISR | Catalog/content pages rendered on first request and cached; per-brand tags purged by the API through the outbox (about 6 s from an admin edit to the live page) |
| Security headers | CSP (frame-ancestors none), referrer policy, permissions policy, nosniff. HSTS is left to the TLS-terminating proxy/CDN |
| Accessibility | Skip link, landmarks, labelled forms with inline errors, ARIA combobox, native `<dialog>` menu (focus trap, Escape), visible focus rings, `prefers-reduced-motion` |

Backend additions: `TrustStorefrontService` middleware, render-call rate-limit exemption,
`StorefrontCache` + revalidation observers (Catalog, Content, Settings, Reviews),
`RevalidateStorefront` outbox handler, `platform:seed-demo-catalog --restock`, the demo brand
**bravo is now "Velvina Home" on the velvina-home theme**.

## 3. Found and fixed along the way

1. **Signed customer calls lost their brand** when the API applied the customer IP: once
   `REMOTE_ADDR` was the customer's, Symfony stopped trusting the proxy's `X-Forwarded-Host`, so
   cart creation answered 404. The forwarded host/scheme are now resolved first; regression test.
2. **Sign-out did not sign out on the production build**: `__Host-` cookies ignore a delete
   without `Secure`. Cookies are now expired with their original attributes (ADR-0017).
3. **Per-customer pages could be served from the ISR cache** once the brand layout became
   statically generated (a stale "Signed in as …" page after sign-out). Every per-customer page
   now declares `force-dynamic`; E2E checks two customers and `Cache-Control`. Found before any
   commit or deployment.
4. Search facets showed category ids instead of names; the search box was not prefilled and
   did not work without JavaScript. Fixed and covered.
5. **Contract documentation corrections** (no behaviour change): query parameters of product
   listing, search, suggestions, reviews and account orders, and the request bodies of register,
   login, profile update and addresses, were missing from the OpenAPI document. They are now
   documented; the drift guard reported the newly required body fields as "now requires body
   field", which is the endpoints' existing behaviour becoming visible, not a v1 break.
6. A test counted outbox messages left by other tests in the shared fixture brand; it now counts
   only its own.

## 4. Deferred / known gaps

- **CI**: the E2E and performance jobs need a running API with the demo brands; they run only
  when the repository variable `STOREFRONT_E2E` is `true`. The performance budget is a
  Playwright + CDP lab script (`pnpm perf:lab`: LCP, CLS, TBT), not Lighthouse. Lighthouse CI
  and a real-device INP measurement belong with the CI runner.
- Demo catalog has no images, so LCP is text/placeholder-driven. Re-measure with real
  photography.
- Only one tenant theme (velvina-home); prifit and vogsix themes when those brands onboard.
- Admin → storefront preview, blog/articles and multi-language content are not in v1.
- Local portable PHP has no CA bundle, so the breached-password check cannot reach the internet
  (dev only; the check is skipped when unreachable).
- Next.js hosting decision (self-hosted Node vs managed) remains open (ADR index, deferred).

## 5. Operating notes

- Three secrets must match across deployments: `SERVICE_AUTH_SECRET`,
  `STOREFRONT_REVALIDATE_SECRET` (API ↔ storefront) and `STOREFRONT_SESSION_SECRET`
  (storefront only). Rotating the session secret signs every customer out.
- The storefront must run behind a proxy that overwrites `X-Forwarded-For`.
- After changing outbox handlers or jobs, restart queue workers (`php artisan queue:restart`):
  a long-running worker keeps the old handler map. It happened during this phase.
