# Runbook: verify tenant isolation

**When:** before every release (CI runs it on every change), after any change to tenancy,
caching, queues, sessions, storage or authentication, and against staging before onboarding
a new brand.

## What it proves (spec §4.8, tenancy.md §7)

| # | Channel | Test file |
|---|---|---|
| 1 | Database — rows and `CONNECT` privileges | `tests/Isolation/DataAndApiIsolationTest.php`, `tests/Feature/Platform/DatabasePrivilegeTest.php` |
| 2 | API — customer tokens | `DataAndApiIsolationTest.php` |
| 3 | Admin — staff credentials, replayed sessions, panel hosts | `AdminAndTopologyIsolationTest.php`, `tests/Feature/Platform/HandoffTest.php` |
| 4 | Cache — facade, flush, injected repository (Redis) | `CacheAndStorageIsolationTest.php` |
| 5 | Search | Phase 2 (Typesense not built yet) |
| 6 | Storage — per-tenant roots | `CacheAndStorageIsolationTest.php` |
| 7 | Queues — real Redis worker, job without tenant fails | `QueueAndSchedulerIsolationTest.php` |
| 8 | Scheduler — once per active brand, skips suspended, survives failures | `QueueAndSchedulerIsolationTest.php` |
| 9 | Two brands of one company | every test above uses brands **B and C of the same company** |
| 10 | Topology — dedicated pinning | `AdminAndTopologyIsolationTest.php` |

## Run it

```bash
vendor/bin/pest --group=isolation
vendor/bin/pest tests/Feature/Platform/DatabasePrivilegeTest.php
```

Prerequisites: PostgreSQL with the roles from `database/setup/bootstrap-landlord.sql` and a
`platform_landlord_test` database (`-v landlord_db=platform_landlord_test`), and Redis. Redis-
dependent tests skip — they do not pass — when Redis is unreachable; a skipped isolation test
is a failed release gate.

## Against staging

Point a checkout at the staging PostgreSQL and Redis (never production) with a
`platform_landlord_test` database of its own, then run the same commands. Fixture brands are
created with the `ptest_` prefix and can be dropped with `DROP DATABASE ptest_fix_a` etc.

## If it fails

Stop the release. An isolation failure is a potential data breach between brands, not a
flaky test. Open an incident, identify the channel from the failing test's name, and do not
re-run until the cause is understood.
