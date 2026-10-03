# Runbook: incident response

What to do when something is wrong in production: how bad it is, who acts, what to check first,
how to tell the brands, and what to write down afterwards.

## Contacts (owner to fill in before launch)

| Role | Who | How |
|---|---|---|
| Incident lead (platform owner) | _name_ | _phone / WhatsApp_ |
| Engineer on call | _name_ | _phone_ |
| Hosting / database provider support | _provider_ | _support URL, account id_ |
| Brand contacts (one per brand) | see platform panel → companies | owner email + phone |

## Severity

| Level | Meaning | Examples | Response |
|---|---|---|---|
| **SEV1** | All brands down, data loss, or a security breach | API or database down; leaked secret; one brand seeing another's data | Now, any hour. Lead informed at once. Brands told within 1 h |
| **SEV2** | One brand down, or checkout broken anywhere | Brand storefront 5xx; orders cannot be placed; payments not confirming | Within 1 h in business hours, 4 h otherwise |
| **SEV3** | Degraded, workaround exists | Search stale; emails delayed; admin page error; slow pages | Next business day |

Cross-brand data exposure is always **SEV1**, however small.

## Where alerts come from

- **Uptime monitor** on `https://<platform host>/health` (503 when database, Redis, Typesense,
  the queue backlog or failed jobs are not fine). With `X-Health-Token` it says which part.
- **PostHog** error tracking — API and storefront errors, tagged with brand, request id and
  release (ADR-0019). The request id in an API error response finds the exact log line.
- **Failed-job alert** to `MONITORING_ALERT_EMAIL`.
- Brand owners and customers.

## First 15 minutes

1. Acknowledge; name one incident lead. Open an incident note (time, symptoms, actions — keep
   writing as you go).
2. Scope: all brands or one? (`/health`; open two brands' storefronts; PostHog grouped by brand.)
3. Recent change? Releases are listed in `releases/` on the server with timestamps. If the
   incident started with a release: **roll back first, investigate later** (`rollback.md`).
4. Then by symptom:

| Symptom | Check | Act |
|---|---|---|
| All 5xx | `/health` detail; PHP-FPM and nginx status; disk space; database reachability | Restart the failed service; database provider status page; roll back a release |
| One brand 503 | Is it suspended? (`tenants.status`) | `tenant:activate` if it should not be |
| One brand 5xx | PostHog errors for that brand; its database reachable? | Restore path if its data is damaged (`restore-tenant.md`) |
| Search empty or stale | Typesense up? `queues:search` length | `php artisan search:reindex --tenant=<id>` |
| Emails / revalidation not going out | Horizon running? failed jobs; outbox table | Restart Horizon; `php artisan queue:retry all` after fixing the cause |
| Checkout failing | API error codes in PostHog; stock reservations; shipping/payment method config | Fix config in the brand admin; if code: roll back |

## Security incident

Treat as SEV1. Order: **contain, preserve, rotate, tell.**

1. Contain: suspend affected brands (`tenant:suspend`) or block at the edge; revoke the
   compromised account (platform panel → users, or the brand admin).
2. Preserve evidence before changing more: copy logs, the audit tables (landlord `platform_activity_log`,
   `platform_login_audits`; each brand's `activity_log`, `login_audits`), and take `tenant:export` of affected brands.
3. Rotate what may be exposed (`rotate-secrets.md`); a leaked `APP_KEY` means procedure A *and*
   B for every brand.
4. Tell: brand owners within 24 h with what is known; customers and authorities as the law and
   the brand's privacy policy require (the brand is the controller of its customers' data; the
   platform helps with facts).

## Telling the brands

Short and factual, on the channel the brand owner uses (WhatsApp/email): what is affected, since
when, what they should do (usually nothing), when the next update comes. Update at least hourly
for SEV1, every 4 h for SEV2, and once resolved.

## Afterwards (within 5 business days)

Write a short review in the incident note: timeline, impact (brands, orders, data), root cause,
what helped, what was missing. Each follow-up becomes an issue in the **core** backlog — a fix
ships to every brand (spec §13 Phase 8). For restores, also record restore point and duration
(`tenant_restores` holds the numbers).
