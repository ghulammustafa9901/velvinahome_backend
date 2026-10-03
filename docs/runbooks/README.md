# Runbooks

Operational procedures (spec §9). Each runbook is written when the capability it describes
exists, and is **tested** rather than assumed — a runbook nobody has followed is a guess.
One store per installation (ADR-0020): every procedure here applies to one installation.

| Runbook | Status |
|---|---|
| [`install-store.md`](install-store.md) — set up an installation for a store: database, `.env`, `store:install`, storefront, DNS | written; `store:install` covered by tests; first real run on staging (hosting not chosen) |
| [`deploy.md`](deploy.md) — zero-downtime deploy with `app:migrate` as a gate; server setup | written; `deploy/release.sh` tested with stubs (gate, auto-rollback, pruning) |
| [`rollback.md`](rollback.md) — automatic and manual rollback, a failed migration | written; `deploy/rollback.sh` tested with stubs |
| [`restore-database.md`](restore-database.md) — backups, restore drill, restore (**drill required**) | written; drill automated weekly (`db:backup --drill`) and covered by tests |
| [`rotate-secrets.md`](rotate-secrets.md) — APP_KEY, database roles, shared and third-party secrets | written; `secrets:reencrypt` tested |
| [`incident-response.md`](incident-response.md) — severity levels, first steps, security incidents, comms | written; contacts to be filled in by the owner |

Related: load test and performance budgets — [`../performance/load-test.md`](../performance/load-test.md).

Targets to design against (spec §9): **RPO ≤ 15 minutes** with point-in-time recovery, or
≤ 24 hours on logical backups alone; **RTO ≤ 4 hours**.
