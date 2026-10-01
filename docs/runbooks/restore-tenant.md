# Runbook: restore one tenant

Restores **one brand** from a `tenant:export` dump without touching any other brand: each
brand has its own database and roles, and a restore only ever connects to that database.

- Targets (spec §9): RPO ≤ 24 hours on nightly logical exports alone (≤ 15 minutes once the
  host's point-in-time recovery is configured — hosting not chosen yet); RTO ≤ 4 hours.
- Every restore and drill is recorded in the landlord table `tenant_restores` (status, tables and
  rows checked, differences, duration, who) and in the platform audit log
  (`tenant.restore_drill` / `tenant.restore_in_place`).

## Backups that exist

| What | When | Where |
|---|---|---|
| `tenants:backup` — `pg_dump --format=custom` of every active/suspended brand + media manifest | nightly 01:30 | `storage/app/exports/<slug>/<stamp>.dump` (+ `.media.json`) |
| `tenants:backup --drill` — the same, and each fresh export is restored into a scratch database and compared | Sundays 01:30 | as above |
| `tenant:export <slug>` — on demand; also taken automatically before `tenant:delete` and before an in-place restore | manual | as above |

The newest `TENANT_EXPORT_RETENTION` (default 14) exports per brand stay on disk; older files are
deleted, their `tenant_backups` rows kept as history. **Exports on the application server are
not off-site backups** — when hosting is chosen, sync `storage/app/exports` to separate storage
(and enable managed PITR). Media files live on the media disk; the manifest lists them.

Requirements: `pg_dump` and `pg_restore` of the server's major version (PostgreSQL 17), set via
`PG_DUMP_BINARY` / `PG_RESTORE_BINARY` if not on `PATH`.

## 1. Restore drill (safe, any time)

```bash
php artisan tenant:restore <slug>               # fresh export, then drill
php artisan tenant:restore <slug> --backup=<id> # drill a specific export
```

What happens: the checksum is verified, a scratch database `<db>_drill_<stamp>` owned by the
brand's migration role is created, the dump is restored into it in one transaction
(`--exit-on-error --single-transaction`), every table's row count is compared with the live
database, and the scratch database is dropped (also on failure).

Pass = `Status succeeded`, no differences. A drill of an **older** backup reports differences
for tables that changed since (expected); a fresh export must show none.

Last drill on the dev brand `bravo` (2026-10-01): succeeded, 80 tables, 360 rows, 3.3 s.

## 2. In-place restore (disaster recovery)

Use when a brand's data is lost or corrupted (bad import, destructive bug, operator error).

1. **Decide the backup.** List them: `select id, finished_at, size_bytes from tenant_backups
   where tenant_id = '<id>' and kind = 'export' and status = 'succeeded' order by finished_at desc;`
   Everything after that backup's time will be lost — tell the brand owner which window.
2. **Drill it first:** `php artisan tenant:restore <slug> --backup=<id>` must succeed.
3. **Suspend the brand** (storefront shows maintenance, no orders taken):
   `php artisan tenant:suspend <slug>`
4. **Restore:**
   `php artisan tenant:restore <slug> --backup=<id> --in-place --confirm=<slug>`
   This takes a safety export of the current state first (a way back), replaces the database
   contents in one transaction (`--clean --if-exists`), re-applies the role privileges, and runs
   tenant migrations (bringing an older dump up to date and re-locking the append-only ledgers).
   If any step fails, the transaction leaves the database as it was; the command exits non-zero
   with the reason.
5. **Check:** sign in to the brand's admin, open recent orders, stock levels and the reports
   page; compare with what the owner expects for the restore point.
6. **Reactivate and rebuild the search index:** `php artisan tenant:activate <slug>`, then
   `php artisan search:reindex --tenant=<tenant id>`. Caches and storefront pages refresh on
   their own.
7. Record the incident (what was lost, restore point, duration) per `incident-response.md`.

To undo an in-place restore, restore the safety export it took (step 4) the same way.

## Failures

| Symptom | Meaning / action |
|---|---|
| `backup file does not match its checksum` | The dump was altered or truncated. Use an older backup; investigate the storage. |
| `not a usable export of this brand` | Wrong backup id, failed export, or file pruned. Pick another id. |
| `Suspend the brand before restoring it in place` | Step 3 was skipped. |
| `pg_restore` error in the output | Usually a version mismatch of `pg_restore` vs the dump; use the server's major version. |
| Drill: `Table X is missing from the backup` | The backup predates migration that created X — expected for old backups only. |
