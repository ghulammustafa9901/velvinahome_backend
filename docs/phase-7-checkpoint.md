# Phase 7 Checkpoint — Hardening & Production Readiness

**Date:** 2026-10-02 · **Status:** built — everything that does not need a server; the rest waits
for the hosting decision
Plan: [plans/phase-7.md](plans/phase-7.md) · Decisions: [ADR-0019](adr/0019-monitoring-with-posthog.md)
· Security review: [security/asvs-l2.md](security/asvs-l2.md) · Performance:
[performance/load-test.md](performance/load-test.md) · Runbooks: [runbooks/](runbooks/README.md)

## 1. Exit criteria

| Criterion | Result |
|---|---|
| All Definition-of-Done items in §15 checked | **14 of 16 met, 2 partly met** (#8 online gateway — deferred by you; #15 first real deploy — needs hosting) — see §3. Two met items also have hosting-only parts (#13, #14). No item is blocked by missing code. |
| Restore drill succeeds | **Met locally.** `tenant:restore` restores a `tenant:export` dump into a scratch database and compares every table's row count; runs automatically every Sunday (`tenants:backup --drill`). Off-site copies and point-in-time recovery come with hosting. |
| Owner sign-off | **Waiting for you.** |

**Test suites:** platform-api **394 passing** (1,732 assertions) on real PostgreSQL 17, Redis and
Typesense; Larastan level 8 and Pint clean; `api:contract --check` passes (no API change); deploy
scripts ShellCheck-clean and 10/10 in `deploy/tests/release.test.sh` (also a CI job now).
platform-web: ESLint, strict typecheck, Vitest clean.

## 2. Milestones

| # | Milestone | Result |
|---|---|---|
| 7.1 | Monitoring and alerting | PostHog (EU) error reporting for API and storefront, tagged with brand, request and release, with personal data removed (ADR-0019); deep `/health` (database, Redis, Typesense, queue backlog, failed jobs; detail behind a token); request id from storefront to API; failed-job alert email |
| 7.2 | Dependency and header audit | `composer audit` and `pnpm audit` clean; Dependabot in both repos; security headers on API, panels and storefront, proven by tests; production errors reveal nothing |
| 7.3 | Security review (ASVS L2) | Every chapter reviewed with evidence. **Fixed:** Turnstile human check on sign-up, sign-in, password reset and order placement; panel sessions 30 min idle / 12 h maximum; storefront no longer trusts a client-supplied `X-Forwarded-For`; key rotation tooling (below). Open items need hosting (TLS, WAF, central logs) |
| 7.4 | Backup and restore drill | `tenant:restore` (drill or in place, with a safety export first), nightly exports with retention, weekly automated drill, runbook |
| 7.5 | Load test and optimization | k6 script (browse, search, cart + checkout quote, signed like the storefront), catalog generator, `imports:csv`. **Three fixes** (below). Capacity numbers need staging |
| 7.6 | Deploy and environments (local part) | `deploy/release.sh`: atomic releases, `tenants:migrate` as a gate, health check against the new release, **automatic rollback**; `deploy/rollback.sh`; both tested in CI. Server setup and the expand-then-contract migration rule documented |
| 7.7 | Runbooks and docs | New: deploy, rollback, rotate secrets, incident response. **New commands** `secrets:reencrypt` (move every stored secret to a new `APP_KEY`) and `tenant:rotate-db-passwords` |

## 3. Definition of Done (§15) walk-through

| # | Item | State |
|---|---|---|
| 1 | Brands provisioned by command/panel, one database each; isolation across DB, cache, queues, storage, search, incl. brands of one company | ✅ (isolation suite, Phase 1–2) |
| 2 | Owner with several brands switches from one login | ✅ (brand switcher, Phase 1) |
| 3 | Tenant migrations transactional; a failing one leaves its database unchanged and blocks the release | ✅ (`tenants:migrate`; now enforced by `release.sh`, tested) |
| 4 | One codebase, two tenants, different domains and themes | ✅ (alpha default theme, bravo velvina-home, Phase 5) |
| 5 | Shared and dedicated topology by configuration only | ✅ (Phase 1, isolation tests) |
| 6 | Business logic in Actions; module boundaries enforced by architecture tests | ✅ |
| 7 | Inventory ledger append-only in the database, concurrency-safe, reconciled nightly | ✅ (02:30 per brand) |
| 8 | COD, manual transfer **and one online gateway**; payments confirmed by verified callbacks; reconciliation | ◐ COD and manual transfer done; online gateway **deferred by you** (adapter contract and verified webhook intake exist) |
| 9 | Typesense: typos, prefix, facets, filters, sorting, per-brand synonyms | ✅ |
| 10 | API v1 documented, contract-tested, used through the generated client | ✅ |
| 11 | Core Web Vitals and full SEO | ✅ in lab (Phase 5); field data after launch |
| 12 | Tenant admin and platform panel complete for MVP, with roles and 2FA | ✅ |
| 13 | Security requirements implemented and reviewed | ✅ reviewed (ASVS L2); hosting items open (TLS, WAF, central logs) |
| 14 | Backups automated per brand; restore drill documented and passed | ✅ locally; off-site copies and PITR with hosting |
| 15 | CI/CD with zero-downtime deploys and tenant migrations; rollback tested | ◐ CI ✅; release and rollback scripts tested with stubs; **first real deploy on staging** |
| 16 | Documentation, ADRs, runbooks, onboarding checklist | ✅ (provisioning runbook is finalised during the first onboarding, Phase 8) |

✅ met · ◐ partly met

## 4. Found and fixed along the way

1. **Imports flooded the search queue.** A 5,000-product import queued ~16,300 index jobs —
   about 2.7 hours of indexing during which search was stale. Imports now index in bulk: **23
   jobs, 20 seconds.** This matters for the Phase 8 WooCommerce migrations.
2. **Every API request depended on the landlord database** to find its brand. The lookup is now
   cached safely (plain data, passwords stay encrypted, forgotten on every change; a suspension
   still takes effect on the next request). Saves a connection and three queries per request and
   takes the landlord off the storefront's critical path.
3. **The connection pooler was planned but never connected** (`DB_POOLER_HOST` was read by
   nothing). Without it every request opens a new PostgreSQL backend; measured locally, database
   time per request falls about **10×** with warm connections. Now wired for runtime connections;
   migrations stay direct. *It has not run through a real PgBouncer yet* — first thing on staging.
4. **`APP_KEY` could not really be rotated**: Laravel can read with an old key, but nothing moved
   stored secrets (brand database passwords, 2FA secrets, payment credentials) to the new key, so
   the old key could never be retired. `secrets:reencrypt` does it; a test fails if a module adds
   an encrypted field without declaring it.
5. Brand database passwords had no rotation path — `tenant:rotate-db-passwords`.
6. **Order emails could be starved by failing retries.** The outbox published due messages
   oldest first, 100 per run; with a storefront down, its failing revalidations pile up ahead of
   a new order confirmation. Found when the isolation suite started failing intermittently (the
   test brands had ~900 failed revalidations queued). New messages now always go before retries;
   regression test in `OutboxTest`.
7. `.env.example` cleanup: unused Sentry, Pulse and backup settings removed, a duplicated
   Turnstile key removed, pooler opt-in.

## 5. Waiting on decisions

| Needs | Unblocks |
|---|---|
| **Hosting choice** (provider, managed PostgreSQL with PITR, region) | Staging + production, first real deploy, TLS/WAF/HSTS preload, off-site backups, central logs, load test at capacity (`PROFILE=load`/`stress`), PgBouncer validation |
| Cloudflare account | Turnstile keys (code ready, off until keys exist), WAF |
| Confirm API latency budgets (`performance/load-test.md`) | k6 thresholds become the release gate |
| Transactional email provider | Real emails (code uses Laravel mail; provider is configuration) |
| Online payment gateway | DoD #8 (still deferred by you) |
| Incident contacts | `runbooks/incident-response.md` contact table |

## 6. Next: Phase 8 — Launch & Tenant Onboarding

Phase 8 starts on live infrastructure: launch your own brand first and watch it for 2–4 weeks,
then onboard Prifit, VogSix and Velvina Home one at a time (`tenant:create`, theme, WooCommerce
import — dry run first — and the launch checklist). It cannot start until hosting is decided and
this checkpoint is signed off.
