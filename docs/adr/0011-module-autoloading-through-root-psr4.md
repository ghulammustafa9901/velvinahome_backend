# ADR-0011: Module autoloading through the root composer.json

- **Status:** Accepted; amended by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) — one store per installation, so tenant, brand and landlord passages below are history (amends the "Physical layout" part of ADR-0007)
- **Date:** 2026-09-26
- **Spec reference:** §3.3 (FINAL: modular monolith), §3.3 internal layout (YOUR CALL)

## Context

ADR-0007 wired each module in through a Composer **path repository** with
`"symlink": true`, so `vendor/modules/<module>` would be a link to `modules/<Module>`.

On the Windows development machine Composer could not create the junctions and silently
fell back to **copying** each module into `vendor/`. Every edit under `modules/` was then
invisible to the running application until the next `composer update`, and new classes
failed to autoload. This surfaced the moment Phase 1 code was added.

## Decision

- Every module's namespace is mapped in the **root** `composer.json` `autoload.psr-4`
  (`Modules\Platform\` → `modules/Platform/src/`, one line per module).
- Module service providers are registered explicitly in `bootstrap/providers.php`, with
  `Support` first and the platform panel before the tenant admin panel.
- Each module keeps its own `composer.json` as its **manifest** (name, description, and the
  place to declare module-specific dependencies later). The architecture tests still discover
  modules by that file.
- Seeders live in `src/Seeders/` (autoloaded) rather than `database/seeders/`.

## Consequences

### Positive

- Edits are live immediately on every OS; no copy/symlink behaviour to reason about.
- Provider order is explicit and reviewable instead of depending on package discovery order.

### Negative / costs

- Adding a module is two extra lines (PSR-4 entry and provider). Forgetting either fails
  loudly: the architecture test "declares a service provider for every module" and class
  autoloading both break immediately.
- A module can no longer be extracted to its own package without adding the path repository
  back. That option remains cheap to restore.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep path repositories, `symlink: true` | The failure mode (silent copy) is worse than no feature. |
| Path repositories with `symlink: false` | Mirrors are stale after every edit by design. |
| One `Modules\` → `modules/` mapping | Would require dropping the `src/` level from every module. |
