# Runbooks

Operational procedures (spec §9). Each runbook is written when the capability it describes
exists, and is **tested** rather than assumed — a runbook nobody has followed is a guess.

| Runbook | Written in | Status |
|---|---|---|
| `deploy.md` — zero-downtime deploy including `tenants:migrate` | Phase 7 | not written |
| `rollback.md` — one-click rollback, including a failed tenant migration | Phase 7 | not written |
| `restore-tenant.md` — restore one tenant without affecting others (**drill required**) | Phase 7 | not written |
| `rotate-secrets.md` — APP_KEY, DB roles, gateway credentials, BFF session secret | Phase 7 | not written |
| `provision-tenant.md` — run `tenant:create` and complete the go-live checklist | Phase 1 (draft), finalised Phase 8 | not written |
| `verify-isolation.md` — run the isolation suite against staging | Phase 1 M1.8 | not written |
| `incident-response.md` — severity levels, comms, who to call | Phase 7 | not written |

Targets to design against (spec §9): **RPO ≤ 15 minutes** with point-in-time recovery, or
≤ 24 hours on logical backups alone; **RTO ≤ 4 hours**.
