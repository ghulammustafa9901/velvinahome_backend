# Runbook: back up and restore the store database

- Targets (spec §9): RPO ≤ 24 hours on nightly logical backups alone (≤ 15 minutes once the
  host's point-in-time recovery is configured — hosting not chosen yet); RTO ≤ 4 hours.

## Backups that exist

| What | When | Where |
|---|---|---|
| `db:backup` — `pg_dump --format=custom` of the store database + a manifest (SHA-256, size, list of stored files) | nightly 01:30 (Mon–Sat) | `BACKUP_PATH` (default `storage/app/backups/<db>_<stamp>.dump` + `.json`) |
| `db:backup --drill` — the same, and the fresh dump is restored into `<db>_restore_drill` and compared table by table | Sundays 01:30 | as above |

The newest `BACKUP_RETENTION` (default 14) dumps stay; older ones are deleted. **Dumps on the
application server are not off-site backups** — when hosting is chosen, sync the backup
directory to separate storage (and enable managed PITR). Media files live in object storage
(enable bucket versioning); the manifest lists them.

Requirements: `pg_dump` and `pg_restore` of the server's major version (PostgreSQL 17)
(`PG_DUMP_BINARY` / `PG_RESTORE_BINARY` if not on `PATH`), and the scratch database
`<db>_restore_drill` created once with `database/setup/bootstrap-database.sql`.

## 1. Restore drill (safe, any time)

```bash
php artisan db:backup --drill
```

The checksum is verified, the scratch database is emptied and the dump restored into it
(`pg_restore --exit-on-error`), and every table's row count is compared with the live database.
Pass = "Restore drill passed". Rows written between the dump and the comparison show up as
differences — run it at a quiet hour.

## 2. Restore (disaster recovery)

Use when the data is lost or corrupted (bad import, destructive bug, operator error).

1. **Choose the dump** in the backup directory. Everything after its time will be lost — tell
   the store owner which window. Verify it: its `.json` manifest's `sha256` must match
   `sha256sum <dump>`.
2. **Stop traffic:** `php artisan down` (the storefront shows its error page; no orders are
   taken) and stop Horizon and cron.
3. **Take a safety dump of the current state:** `php artisan db:backup` (a way back).
4. **Restore** as the migration role, replacing the contents:
   ```bash
   PGPASSWORD=<migrator password> pg_restore --clean --if-exists --no-owner --no-privileges \
     --single-transaction --exit-on-error -h <host> -U store_migrator -d <db> <dump>
   ```
   Then re-apply privileges and bring the schema up to date (also re-locks the append-only
   ledgers):
   ```bash
   psql -U postgres -f database/setup/bootstrap-database.sql -v db=<db> \
        -v app_password=unused -v migrator_password=unused -v backup_password=unused
   php artisan app:migrate
   ```
5. **Check:** sign in to the admin, open recent orders, stock levels and the reports page;
   compare with what the owner expects for the restore point.
6. **Rebuild the search index and reopen:** `php artisan search:reindex`, start Horizon and
   cron, `php artisan up`. Caches and storefront pages refresh on their own.
7. Record the incident (what was lost, restore point, duration) per `incident-response.md`.

To undo a restore, restore the safety dump from step 3 the same way.

## Failures

| Symptom | Meaning / action |
|---|---|
| `The dump no longer matches its checksum` | The dump was altered or truncated. Use an older one; investigate the storage. |
| `The dump or its manifest is missing` | Pruned or copied without its `.json`. Pick another dump. |
| `pg_restore` error | Usually a version mismatch of `pg_restore` vs the dump; use the server's major version. |
| Drill reports a table `missing` | The dump predates the migration that created it — expected for old dumps only. |
