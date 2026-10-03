# Runbook: roll back a release

A rollback switches **code** back to an earlier release in seconds. It never reverses
migrations — the migration rule in [`deploy.md`](deploy.md) (expand, then contract) is what makes
the previous code run correctly on the newer schema.

## Automatic

`deploy/release.sh` rolls back by itself when the new release does not pass `/health` (new
release id answering, database, Redis and search reachable). Nothing to do but read the output
and the release's errors in PostHog.

## Manual

When a release is live and healthy but wrong (a bug, a bad page, a broken flow):

```bash
export DEPLOY_BASE=/srv/platform-api
/srv/platform-api/current/deploy/rollback.sh                 # to the release before the live one
/srv/platform-api/current/deploy/rollback.sh <release dir>   # to a named one: ls $DEPLOY_BASE/releases
```

It switches `current`, reloads PHP-FPM and restarts Horizon (queued jobs continue on the old
code). Then:

1. Check `/health` reports the old release id: `curl -H "X-Health-Token: …" https://<platform host>/health`.
2. Roll the storefront back too if it was released together (its own previous release).
3. Note the bad release id; fix forward with a new release.

## A failed tenant migration

There is nothing to roll back: `tenants:migrate` runs each tenant in one transaction and blocks
the release on any failure, so the failed tenant's database is unchanged and the old code is
still live (`deploy.md`, step 4). The tenants that did migrate are on an expand-only schema the
old code runs on.

## Data damage

When a release damaged data (not just code), a code rollback is not enough: restore the affected
brand from a backup with [`restore-tenant.md`](restore-tenant.md), and record the incident
(`incident-response.md`).
