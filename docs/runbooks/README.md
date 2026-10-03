# Runbooks

Operational procedures (spec §9). Each runbook is written when the capability it describes
exists, and is **tested** rather than assumed — a runbook nobody has followed is a guess.

| Runbook | Written in | Status |
|---|---|---|
| [`deploy.md`](deploy.md) — zero-downtime deploy with `tenants:migrate` as a gate; server setup | Phase 7 M7.6 | written; `deploy/release.sh` tested in CI with stubs (gate, auto-rollback, pruning). First real run on staging (hosting not chosen) |
| [`rollback.md`](rollback.md) — automatic and manual rollback, a failed tenant migration | Phase 7 M7.6 | written; `deploy/rollback.sh` tested in CI with stubs |
| [`restore-tenant.md`](restore-tenant.md) — restore one tenant without affecting others (**drill required**) | Phase 7 M7.4 | written; drill passed locally (automated weekly via `tenants:backup --drill`) |
| [`rotate-secrets.md`](rotate-secrets.md) — APP_KEY, brand DB roles, platform DB roles, shared and third-party secrets | Phase 7 M7.7 | written; `secrets:reencrypt` and `tenant:rotate-db-passwords` tested |
| [`provision-tenant.md`](provision-tenant.md) — run `tenant:create` and complete the go-live checklist | Phase 1 (draft), finalised Phase 8 | draft |
| [`verify-isolation.md`](verify-isolation.md) — run the isolation suite against staging | Phase 1 M1.8 | written; run locally, not yet against staging |
| [`incident-response.md`](incident-response.md) — severity levels, first steps, security incidents, comms | Phase 7 M7.7 | written; contacts to be filled in by the owner |

Related: load test and performance budgets — [`../performance/load-test.md`](../performance/load-test.md).

Targets to design against (spec §9): **RPO ≤ 15 minutes** with point-in-time recovery, or
≤ 24 hours on logical backups alone; **RTO ≤ 4 hours**.
