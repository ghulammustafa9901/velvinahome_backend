# ADR-0012: Tenant scoping of cache, sessions and framework singletons

- **Status:** Superseded by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) (single-store application, 2026-10-03)
- **Date:** 2026-09-26
- **Spec reference:** §4.4 (FINAL: everything is tenant-aware), §4.8 (isolation tests)

## Context

`docs/architecture/tenancy.md` §3 planned a cache key prefix `t:{id}:` and assumed the
stancl/tenancy bootstrappers make every framework service tenant-aware. Building the Phase 1
isolation suite against real Redis showed four places where that assumption does not hold:

1. **Injected cache repository.** Laravel binds `cache.store` (the
   `Illuminate\Contracts\Cache\Repository` contract) as a singleton from a *deferred*
   provider. stancl's cache bootstrapper replaces the cache manager but not this singleton, so
   any class that injects `Repository` wrote untagged, cross-tenant keys. **The isolation test
   caught a real leak.**
2. **`Cache::flush()`** on a prefixed Redis store is `FLUSHDB` — one tenant could wipe every
   tenant's cache.
3. **spatie/laravel-permission** keeps permissions in a process-wide singleton under one
   cache key. In a queue worker that served brand B, brand C's permission checks would be
   answered from B's role ids.
4. **Sessions.** Cookies are host-scoped, but a session cookie replayed onto another brand's
   admin host would load a session whose staff id means a different person there.

## Decision

- **Cache: tags, not prefixes.** stancl's `CacheTenancyBootstrapper` tags every cache call
  with `t:{tenant_id}`; flushing in tenant context flushes only that tag. The
  `cache.store` binding is re-registered (after the deferred cache provider loads) to return
  the tenant-tagged repository while tenancy is initialised.
- **Permissions:** `PermissionCacheBootstrapper` re-keys the permission cache per tenant and
  discards the in-memory copy on every tenancy switch.
- **Sessions:** `ScopeSessionToTenant` stamps each session with `tenant:{id}` or `platform`
  and refuses (403, session invalidated) a session presented on any other scope.
- **Livewire:** its shared update endpoint initialises tenancy from the host
  (`InitializeTenancyForPanelRequest`), exactly like a panel page.
- **Horizon and API docs** are bound to the platform host.
- The cache isolation tests run against **Redis**. The array store cannot prove anything:
  tenancy builds a fresh cache manager — and so a fresh, empty array store — on every
  initialisation.

## Consequences

### Positive

- Each channel above has a test in `tests/Isolation` that fails if the fix is removed.
- Cache invalidation per tenant is one call and cannot touch another tenant.

### Negative / costs

- Tag bookkeeping costs a few extra Redis operations per cache call.
- The `cache.store` override depends on the framework's container binding name; a framework
  upgrade that renames it would reopen the leak — which is exactly why the injected-repository
  isolation test exists.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Key prefix per tenant (as first planned) | `flush()` becomes `FLUSHDB`; prefixing Redis clients needs phpredis, not available on the Windows machine. |
| Separate Redis database per tenant | 16 databases by default; does not scale to 50+ brands. |
| Trust code review not to inject `Repository` | The leak was found by a test, not by review. |
