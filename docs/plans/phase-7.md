# Phase 7 Plan — Hardening & Production Readiness

**Goal (spec §13):** security review against ASVS L2; dependency and header audit; load test
of catalog, search and checkout; query and cache optimization; backup and **restore drill**;
monitoring and alerting; staging and production environments; zero-downtime deploy with tenant
migrations; runbooks; documentation complete.
**Exit:** Definition of Done (§15) checked; restore drill succeeds; owner sign-off.

## Owner decisions (2026-10-01)

- **Hosting: not decided yet.** Everything that does not need a server is done now; staging,
  production and the live deploy pipeline wait for that decision (ADR index: deferred).
- **Monitoring: PostHog (EU cloud)** instead of Sentry (spec §9 named Sentry): errors from the
  API and the storefront, plus storefront product analytics. The project key lives only in
  environment files, never in the repository.

## Milestones

| # | Milestone | Content |
|---|---|---|
| 7.1 | Monitoring and alerting | PostHog error reporting for the API (tenant, request and release tagged, no PII) and the storefront (server and browser); privacy-safe storefront analytics; deep health endpoint (DB, Redis, Typesense, queue backlog, failed jobs); request id propagated storefront → API; failed-job alert |
| 7.2 | Dependency and header audit | `composer audit`, `pnpm audit` clean or justified; security headers on API, admin and storefront verified by tests; production error pages leak nothing |
| 7.3 | Security review (ASVS L2) | Walk §8 and ASVS L2 chapters against the code, record evidence and gaps in `docs/security/asvs-l2.md`, fix what is found |
| 7.4 | Backup and restore drill | `tenant:restore` from a `tenant:export` dump into a fresh database (or in place, after a safety copy), verified by row counts; scripted drill; scheduled exports; runbook |
| 7.5 | Load test and optimization | Repeatable load script for catalog, search, cart and checkout quote; measure, fix hot spots (queries, caches), record results |
| 7.6 | Deploy and environments (local part) | Release script: maintenance-free deploy steps with `tenants:migrate` gating, rollback; documented for the hosting choice later |
| 7.7 | Runbooks and docs | Deploy, rollback, restore a tenant, rotate secrets, provision a tenant, incident response; DoD §15 walk-through |
