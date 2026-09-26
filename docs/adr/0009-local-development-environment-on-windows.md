# ADR-0009: Local development environment on Windows, without Docker

- **Status:** Accepted
- **Date:** 2026-09-26
- **Spec reference:** §2 (FINAL: PHP 8.4+, Docker Compose / Sail), §10 (local environment)
- **Decided by:** owner delegated environment and technical decisions after the Phase 0 checkpoint

> This ADR records a **deviation from two FINAL spec items**. It is deliberately explicit
> about what is lost, because the deviation is a convenience for the current machine and not
> an improvement on the spec.

## Context

Spec §10 requires a Docker Compose local environment (PostgreSQL, Redis, Typesense, Mailpit,
MinIO) reachable with one command, and spec §2 fixes PHP at 8.4+.

The development machine is Windows 10 Pro with XAMPP installed, and at Phase 0 it had:

- PHP 8.3.33 (XAMPP), **not** 8.4
- no Docker, and no WSL distribution installed (enabling WSL needs Windows features plus a
  reboot)
- no PostgreSQL, no Redis, no Typesense, no pnpm
- MySQL and Apache present, both explicitly excluded by the spec

Waiting for a Docker-capable environment would have blocked all of Phase 1. Installing Docker
Desktop unilaterally means a hypervisor change and a reboot on someone's working machine.

## Decision

**Run the backing services natively on Windows for local development, and keep
`docker-compose.yml` as the canonical environment definition** for CI, for any Docker-capable
machine, and for staging/production parity work.

| Service | Local (this machine) | Canonical (Compose / CI / production) |
|---|---|---|
| PHP | XAMPP PHP **8.3.33**, with `pdo_pgsql`, `pgsql` and `intl` enabled in `php.ini` | **8.4+** — CI runs 8.4 |
| PostgreSQL | **PostgreSQL 17** installed natively (EnterpriseDB installer), listening on 5432 | `postgres:17-alpine` |
| Redis | **Memurai Developer 4.1.8**, a Redis-compatible Windows service on 6379 | `redis:7-alpine` |
| Connection pooling | **none locally** | PgBouncer, transaction pooling |
| Search | **deferred to Phase 2**, when Catalog and search are built | `typesense:27.1` |
| Mail | Laravel `log` mailer locally | Mailpit |
| Object storage | **deferred to Phase 2**, when media is built | MinIO locally, Cloudflare R2 in production |

Supporting decisions:

- `composer.json` requires `php: ^8.3` so the project installs here. **CI is the authority on
  the supported version and runs 8.4**, so any 8.4-only syntax or any 8.3 incompatibility is
  caught on every push rather than at deploy time.
- Code is written to be valid on 8.4. No 8.3-only workaround is introduced, and nothing
  depends on behaviour that changed in 8.4.
- `php.ini` was backed up to `php.ini.bak-claude` before the extensions were enabled.
- Memurai is a drop-in Redis protocol implementation, so `predis`/`phpredis` and Horizon treat
  it as Redis. It is a **local development substitute only** — production runs real Redis.

## Consequences

### Positive

- Phase 1 can start today, on this machine, with `composer install`, migrations and the test
  suite all working.
- Every service the platform actually depends on in Phase 1 (PostgreSQL and Redis) is present
  and real, rather than being faked with SQLite and an array cache. Tenant isolation across
  **databases** and across **cache prefixes** can therefore be tested for real, which is the
  entire point of Phase 1.
- `docker-compose.yml` stays the single description of the environment, so nothing is lost
  when the project moves to a Docker-capable machine or a Linux workstation.

### Negative / costs — stated plainly

- **PHP version drift.** Local is 8.3, target is 8.4. CI closes the gap, but a developer can
  write something that passes locally and fails in CI. This is the deviation with the widest
  blast radius and the reason the version should be aligned as soon as practical.
- **No PgBouncer locally.** Transaction pooling forbids session-level state (session `SET`,
  advisory locks held across statements, `LISTEN`/`NOTIFY`). The Compose file runs PgBouncer
  precisely so those assumptions break on a developer's machine; on this machine they will not
  break until staging. Mitigation: the constraint is documented in
  [database-roles.md](../architecture/database-roles.md) §5, and a staging smoke test against
  PgBouncer is a Phase 7 gate. This is a genuine loss of early feedback, not a non-issue.
- **Windows/Linux divergence**: path separators, case sensitivity, file permissions, and
  `pg_dump` invocation for `tenant:export` all differ from production. Tests must not assume
  POSIX paths, and `tenant:export` must locate `pg_dump` through configuration rather than
  assuming it is on `PATH`.
- **Memurai is not Redis.** Protocol-compatible and sufficient for cache, queues and Horizon,
  but not the same binary. Anything that depends on a Redis version-specific feature must be
  verified on real Redis in staging.
- Typesense and object storage are absent locally, so Phase 2 cannot be completed on this
  machine as it stands. That is a known, scheduled problem, not a surprise.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| 8.3-only code reaches `main` | CI runs 8.4 on every push and is a required check |
| Session-state assumption survives to production | Documented pooling constraints; PgBouncer smoke test as a Phase 7 gate; migrations connect directly by design |
| Windows-specific path handling leaks into the codebase | Tests run in CI on Linux, so a POSIX assumption or a Windows assumption fails there |
| Local setup drifts from the Compose definition | The Compose file remains canonical and is what CI uses; this ADR is the only record of the local substitutions |
| Phase 2 blocked by missing Typesense | Revisit before Phase 2 — either Docker/WSL by then, or a native Typesense/MinIO equivalent, which needs its own decision |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Install Docker Desktop | The spec's intent, and still the best answer. It needs hypervisor changes and a reboot on the owner's machine, which is not a change to make unilaterally. **Recommended whenever the owner is ready**, and this ADR is superseded at that point. |
| WSL2 + Docker inside it | Best production parity and my preference. Needs Windows features enabled and a reboot. Same reason as above. |
| A Linux development VPS | Best parity, extra cost, slower edit-test loop. Worth revisiting if the team grows. |
| Keep XAMPP's MySQL and defer PostgreSQL | Would invalidate the entire tenancy design: transactional DDL, `jsonb`, partial indexes, per-database `CONNECT` grants and append-only ledger privileges all come from PostgreSQL. Not an option at any price. |
| SQLite locally, PostgreSQL only in CI | Phase 1 exists to prove per-database tenant isolation. Proving it against SQLite would prove nothing. |

## Update — actual state found when Phase 1 started (2026-09-26)

The table above describes the setup that was intended. When Phase 1 implementation began,
the machine actually had **none** of it: XAMPP's PHP was 8.2.12 (not 8.3), `php.ini` had no
PostgreSQL extensions enabled, and neither PostgreSQL nor Memurai was installed. The decision
of this ADR (native services, Compose file stays canonical) stands; the implementation is:

| Service | Local (this machine), all portable under `%USERPROFILE%\devtools`, no admin rights, no services registered |
|---|---|
| PHP | **8.4.26** NTS (official Windows build) with its own `php.ini` — the version gap with CI is now closed. XAMPP's PHP is untouched. |
| Composer | `composer.phar` 2.10 next to that PHP |
| PostgreSQL | **17.11** binaries (EnterpriseDB zip), cluster in `devtools\pgdata`, started with `pg_ctl`; superuser password in `devtools\pg-superuser.txt` (never in the repo) |
| Redis | **Redis 8.10** Windows build (msys2) instead of Memurai |
| Git / Node | MinGit 2.55, Node 24 LTS |
| Object storage | `OBJECT_STORAGE_DRIVER=local` (per-tenant directories) until MinIO is available |

`devtools\start-services.ps1` starts PostgreSQL and Redis after a reboot; `devtools\env.ps1`
puts the toolchain on `PATH`. Removing the `devtools` folder removes everything.
