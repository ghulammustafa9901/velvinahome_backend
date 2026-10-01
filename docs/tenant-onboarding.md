# Tenant Onboarding Checklist

Written in Phase 8 (spec §13), drafted here so `tenant:create` prints the right go-live
checklist from Phase 1 onward. Every step is either automated by `tenant:create` or an
explicit human action.

**Live checklist (Phase 6):** platform panel → Brands → *Launch checklist* shows, for one
brand, what is actually done — computed from its data every time: hostnames, plan, theme,
store email sender, products on sale, delivery method, payment method, published home page
and a test order. Modules contribute items through `OnboardingChecks`; a check that cannot
run shows as not done.

**Migration tools (Phase 6):** brand admin → Settings → *Imports*: WooCommerce (read-only REST
API keys; run a **dry run** first and read its report), products CSV and stock CSV. Old
product and category URLs get 301s automatically; re-running updates instead of duplicating.
Imported customers have no password (they claim the account with *forgot password*) and no
marketing consent. Afterwards, `php artisan reports:refresh --tenant=<id>` and
`reports:verify` confirm the reports match the orders.

## 1. Commercial and account setup
- [ ] Company record exists (`company:create`) — the contracting/billing party
- [ ] Brand agreed: name, slug, domains (storefront, admin, API)
- [ ] Plan and feature flags chosen (wishlist, reviews, blog, multi-location inventory)
- [ ] Topology decided: shared or dedicated (spec §4.3)

## 2. Provisioning — `tenant:create`
- [ ] Brand record and domains created
- [ ] Database and least-privilege roles created; append-only ledger lockdown applied
- [ ] Tenant migrations run; schema version matches every other tenant
- [ ] Defaults seeded: roles, permissions, settings, tax, shipping, email templates, pages
- [ ] Typesense collections created
- [ ] Object-storage prefix created
- [ ] Owner admin account created; **2FA enrolled before first real use**

## 3. DNS, TLS and edge
- [ ] DNS for storefront, `admin.`, `api.` (and `cdn.` if used)
- [ ] Cloudflare: proxying on, TLS strict, HSTS, WAF rules per host kind
- [ ] Turnstile keys configured for login, registration and checkout
- [ ] Storefront caching rules; API host not cached

## 4. Store configuration (client's team, with support)
- [ ] Store info, currency, timezone, tax mode
- [ ] Locations created (at least one, even for a single-warehouse brand)
- [ ] Shipping zones, methods and rates
- [ ] Payment methods enabled with **the client's own merchant credentials**
- [ ] Email branding and from-address verified with the mail provider
- [ ] Staff accounts and roles created; 2FA enrolled for each

## 5. Catalog and content
- [ ] Catalog imported (WooCommerce importer or CSV) with a dry run reviewed first
- [ ] **Old URL to new URL 301 redirects imported** — non-negotiable for an SEO-safe migration
- [ ] Opening stock loaded as receipts or adjustments with reasons, never direct edits
- [ ] Search synonyms configured (including Roman Urdu variants)
- [ ] Homepage and key pages built from sections; menus configured
- [ ] SEO meta reviewed; sitemap and robots verified

## 6. Theme
- [ ] Theme package created in `themes/<tenant>/`, tokens and logo applied
- [ ] Verified at 375, 768, 1024, 1440, 1920px
- [ ] Lighthouse budget passing on home, category and product templates
- [ ] Accessibility pass (WCAG 2.2 AA)

## 7. Pre-launch verification
- [ ] Full purchase flow end to end on the real domain: COD **and** the online gateway sandbox
- [ ] Webhook received and verified from the live gateway (test transaction)
- [ ] Transactional emails arriving and correctly branded
- [ ] Order appears in admin; staff can confirm, fulfill, cancel and refund
- [ ] Stock moved correctly, and the movement ledger reads correctly for that order
- [ ] Backups running; **a restore of this tenant has been drilled**
- [ ] Uptime monitoring and Sentry releases configured for this domain
- [ ] Isolation suite passes with this tenant present

## 8. Launch
- [ ] DNS cut over; old site redirects verified against the redirects table
- [ ] Monitor closely for the first 72 hours (errors, failed payments, stock mismatches)
- [ ] Issues logged into the **core** backlog — fixes ship to every tenant (spec §13 Phase 8)
