# Runbook: incident response

What to do when something is wrong in production: how bad it is, who acts, what to check first,
how to tell the store owner, and what to write down afterwards. One installation = one store
(ADR-0020); an incident in one installation does not touch another's data.

## Contacts (owner to fill in before launch)

| Role | Who | How |
|---|---|---|
| Incident lead (platform owner) | _name_ | _phone / WhatsApp_ |
| Engineer on call | _name_ | _phone_ |
| Hosting / database provider support | _provider_ | _support URL, account id_ |
| Store owner (one per installation) | _name_ | owner email + phone |

## Severity

| Level | Meaning | Examples | Response |
|---|---|---|---|
| **SEV1** | Store down, data loss, or a security breach | API or database down; leaked secret; one customer seeing another's data | Now, any hour. Lead informed at once. Owner told within 1 h |
| **SEV2** | Checkout or a key flow broken | Storefront 5xx on some pages; orders cannot be placed; payments not confirming | Within 1 h in business hours, 4 h otherwise |
| **SEV3** | Degraded, workaround exists | Search stale; emails delayed; admin page error; slow pages | Next business day |

Exposure of one customer's data to another is always **SEV1**, however small.

## Where alerts come from

- **Uptime monitor** on `https://api.<domain>/health` (503 when database, Redis, Typesense,
  the queue backlog or failed jobs are not fine). With `X-Health-Token` it says which part.
- **PostHog** error tracking — API and storefront errors, tagged with request id and release
  (ADR-0019). The request id in an API error response finds the exact log line.
- **Failed-job alert** to `MONITORING_ALERT_EMAIL`.
- The store owner and customers.

## First 15 minutes

1. Acknowledge; name one incident lead. Open an incident note (time, symptoms, actions — keep
   writing as you go).
2. Scope: everything or one flow? (`/health`; the storefront; the admin; PostHog errors.)
3. Recent change? Releases are listed in `releases/` on the server with timestamps. If the
   incident started with a release: **roll back first, investigate later** (`rollback.md`).
4. Then by symptom:

| Symptom | Check | Act |
|---|---|---|
| All 5xx | `/health` detail; PHP-FPM and nginx status; disk space; database reachability | Restart the failed service; database provider status page; roll back a release |
| Maintenance page | Was `php artisan down` left on? | `php artisan up` |
| Data damaged | What changed it (activity log, imports) | Restore path (`restore-database.md`) |
| Search empty or stale | Typesense up? `queues:search` length | `php artisan search:reindex` |
| Emails / revalidation not going out | Horizon running? failed jobs; outbox table | Restart Horizon; `php artisan queue:retry all` after fixing the cause |
| Checkout failing | API error codes in PostHog; stock reservations; shipping/payment method config | Fix config in the admin; if code: roll back |

## Security incident

Treat as SEV1. Order: **contain, preserve, rotate, tell.**

1. Contain: `php artisan down` or block at the edge; disable the compromised staff account
   (admin → Staff) or revoke the customer's tokens.
2. Preserve evidence before changing more: copy logs, the audit tables (`activity_log`,
   `login_audits`), and take `php artisan db:backup`.
3. Rotate what may be exposed (`rotate-secrets.md`); a leaked `APP_KEY` means procedure A *and*
   B.
4. Tell: the store owner within 24 h with what is known; customers and authorities as the law
   and the store's privacy policy require (the store is the controller of its customers' data;
   the Platform Owner's team helps with facts).

## Telling the store owner

Short and factual, on the channel the owner uses (WhatsApp/email): what is affected, since
when, what they should do (usually nothing), when the next update comes. Update at least hourly
for SEV1, every 4 h for SEV2, and once resolved.

## Afterwards (within 5 business days)

Write a short review in the incident note: timeline, impact (orders, data), root cause, what
helped, what was missing. Each follow-up becomes an issue in the **core** backlog — a fix ships
to every installation with its next deploy. For restores, also record restore point and duration.
