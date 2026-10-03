# Load test and optimization (Phase 7, milestone 7.5)

Run 2026-10-01/02 on the development machine (Windows 10, 4 cores, native PostgreSQL 17,
Redis 8, Typesense 30 — ADR-0009) against brand `charlie` loaded with **5,005 products /
10,543 variants** (`loadtest/generate-catalog.mjs`, imported with `imports:csv`). Tooling and
how to repeat: [`loadtest/README.md`](../../loadtest/README.md).

> **Single-store update (ADR-0020, 2026-10-03):** this run predates the removal of
> multi-tenancy. The measurements stay as recorded. Fix 2 (cached host → tenant resolution) no
> longer exists — there is no landlord lookup at all now, so its saving holds by construction.
> Fix 3's pooler routing now covers the one `pgsql` connection (`pgsql_migrator` bypasses it).

**Capacity is not measured here.** `php -S` on Windows serves one request at a time and
PostgreSQL on Windows starts a process per connection, so local numbers are *service times*
and *hot spots*. Requests per second, saturation point and pool/Typesense sizing are measured
with the `load` and `stress` profiles on staging, on the production hardware class
(deploy runbook, "Before first launch").

## Budgets (proposed — owner to confirm)

The spec sets Core Web Vitals targets for pages, not API latencies. The storefront renders most
pages from ISR, so the API mostly sees cache misses and shopper actions. Proposed server-side
p95 budgets, enforced as k6 thresholds (a run that misses one exits non-zero):

| Kind | Endpoints | p95 | Errors |
|---|---|---|---|
| read | config, category listing, product detail | < 300 ms | < 1% |
| search | search, suggestions | < 400 ms | < 1% |
| cart | cart create/add/read, shipping methods, checkout quote | < 600 ms | < 1% |

## What was found and fixed

| # | Finding | Fix | Effect (measured) |
|---|---|---|---|
| 1 | **Import floods the search queue.** Each product save and each stock change queued its own index job: the 5,000-product import queued **~16,300 jobs**; the worker cleared ~1,350 in 15 minutes (≈2.7 h for all) — a WooCommerce migration would leave search stale for hours | `CatalogImport::inBulk()` / `DeferredSearchSync`: inside an import, Scout's per-save sync is off and touched products are collected; at the end they are indexed (or removed) in chunks of 500. CSV and WooCommerce imports use it. Test: `CsvImportTest` "indexes an import for search in bulk" | 5,005 products indexed with **23 jobs in 20 s** |
| 2 | **Every API request hit the landlord database** to resolve host → tenant (connection + 3 queries), making the landlord a single point of failure for every storefront | `TenantResolver` caches the resolution (raw attribute arrays only — the cache unserializes no objects; DB passwords stay ciphertext) for 5 min; every write to a tenant, domain or credentials, and a key rotation, forgets it. Tests: `TenantResolutionTest` (no landlord query on a warm request; suspension and domain removal apply on the next request) | −80 ms per request locally (one fewer connection); no landlord dependency on the hot path |
| 3 | **PgBouncer was planned (ADR-0001) but not wired:** `DB_POOLER_HOST` was read by nothing. Without a pooler each request starts a fresh PostgreSQL backend: connect (84 ms here) plus cold catalog caches — planning took 10–13 ms per query while execution was < 0.3 ms | Runtime connections (landlord app role, tenant app roles) go through the pooler when `DB_POOLER_HOST` is set; migrator and provisioner connections never do. Compose PgBouncer gets `MAX_PREPARED_STATEMENTS`. Test: pooler routing in `TenantResolutionTest`. Code audited: no session state (no session advisory locks, `SET`, `LISTEN`, temp tables) | See "pooled" column below: DB time per request falls ~10× |
| 4 | Query plans | `EXPLAIN ANALYZE` of listing, product-by-slug and stock availability on 5,005 products: all index scans, execution 0.08–0.28 ms. Query counts stay within the Phase 4 budgets (`QueryBudgetTest`) | No change needed |

## Measurements

Per-endpoint service time, one request at a time, warm application caches
(`loadtest/service-times.ps1`, median ms):

| Endpoint | Before | After fix 2 |
|---|---|---|
| GET storefront/config | 204 | 123 |
| GET categories | 237 | 179 |
| GET products (24, category) | 345 | 270 |
| GET products/by-slug | 349 | 276 |
| GET search (typo) | 336 | 254 |
| GET search/suggest | 200 | 136 |
| GET carts/{id} | 348 | 263 |
| POST checkout/quote | 347 | 266 |

Where the remaining time goes on this machine: ~97 ms is the `php -S` framework floor on
Windows (`GET /up`), ~84 ms the tenant database connection, the rest is the endpoint.

The same requests handled in-process (no process start), with a new PostgreSQL backend per
request versus warm, reused backends — what PgBouncer gives production:

| Endpoint | Direct: total (DB) | Pooled: total (DB) |
|---|---|---|
| storefront/config | 13 ms (0) | 16 ms (0) |
| products, 24 per page | 191 ms (145) | **65 ms (16)** |
| products/by-slug | 145 ms (127) | **30 ms (11)** |
| search, with typos | 149 ms (112) | **58 ms (7)** |

With pooling, every endpoint is well inside its budget on this laptop; the remaining cost is
PHP time (~45 ms for a 24-product listing on Windows PHP — re-check on Linux staging before
optimizing it).

k6 `smoke` (1 shopper per journey, 3 journeys concurrently against the single-threaded
server): **100% of 360 checks passed, 0 failed requests** — catalog, search (with typos), cart,
shipping methods and checkout quote all work end to end with signed shopper traffic. Its
latencies (read p95 1.13 s) are queueing behind one server process and are not a budget result.

## Before launch (staging)

1. PgBouncer in transaction mode in front of PostgreSQL, `DB_POOLER_HOST` set, PgBouncer ≥ 1.21
   with `max_prepared_statements` > 0 — then run the full test suite and the k6 `smoke` profile
   through it (it has not run through a pooler yet: there is none on the development machine).
2. Load-test installation with the generated catalog (`STOREFRONT_URL`/`API_URL` pointing at it); `k6 run -e PROFILE=load` then `-e PROFILE=stress`.
   Record RPS at budget, saturation point, PgBouncer pool usage, Typesense memory per 1,000
   products (ADR-0005 sizing).
3. Confirm or adjust the budgets above with the owner.
