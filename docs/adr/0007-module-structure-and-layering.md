# ADR-0007: Module internal structure and enforced layering

- **Status:** Accepted
- **Date:** 2026-09-26
- **Spec reference:** §3.3 (FINAL: module map; **YOUR CALL**: internal layout), §3.4 (FINAL)

## Context

The spec fixes the module map and the layering rules (Actions hold all business logic; modules
communicate only through contracts and domain events; boundaries enforced automatically in
CI). It leaves each module's internal layout to engineering judgement, requiring only that it
be consistent across modules.

A modular monolith only stays modular if the boundaries are mechanically enforced. Convention
alone erodes the moment a deadline arrives.

## Decision

### Physical layout

Modules live in `modules/<Module>/` with their own `composer.json`, wired into the app through
Composer **path repositories** and PSR-4 namespace `Modules\<Module>\`. Each module owns its
migrations, factories, seeders, routes, Filament components and tests. The full directory
layout is documented in [modules.md](../architecture/modules.md) §2 and is identical for every
module.

Why a `composer.json` per module rather than plain PSR-4 folders: it makes each module's
dependencies explicit and declarable, it lets a module be extracted to its own package later
without restructuring, and Composer itself then complains about a dependency that was never
declared.

### Public surface

A module's public surface is exactly four directories: `Contracts/`, `Data/`, `Events/`,
`Enums/`. Everything else — `Models/`, `Actions/`, `Queries/`, `Support/`, `Http/`,
`Filament/` — is internal and may not be referenced from another module.

This is the rule that makes spec §3.4.3 concrete: `Orders` cannot query a `Catalog` model even
by accident, because importing `Modules\Catalog\Models\Product` fails an architecture test.

### Communication

| Direction | Mechanism |
|---|---|
| Caller needs an answer now | The other module's **contract** interface, resolved from the container |
| Caller causes a consequence elsewhere | A **domain event**, handled by a queued listener in the other module, dispatched after commit |

Concretely, per spec §3.4.3: `Orders` never decrements stock. `PlaceOrder` emits `OrderPaid`;
an `Inventory` listener converts the reservation to a sale. Where two modules would otherwise
depend on each other, the lower-level module publishes the contract and the higher-level one
listens for events.

### Actions

- One Action per business operation, named as an imperative verb phrase (`ReserveStock`,
  `PlaceOrder`, `RefundPayment`). No `Service` classes, no `Manager` classes.
- `final`, single public entry point, constructor-injected dependencies.
- Input is a **validated DTO** (spatie/laravel-data). `$request->all()` never reaches a model.
- **Authorisation is checked inside the Action**, not only in the UI, so Filament, the API, a
  console command and a future admin API all get the same enforcement.
- Transaction boundaries live in the Action. Side effects are dispatched after commit.

### Thin callers

Controllers, Filament resources and pages, Artisan commands, jobs and webhook handlers
validate input, call one Action, and shape the response. They contain no business rules.
This is what allows the landlord panel and the Artisan lifecycle commands to be the same
behaviour by construction (spec §4.6).

### Enforcement

Pest architecture tests, running in CI on every change, covering the seven checks listed in
[modules.md](../architecture/modules.md) §4. Deptrac is added later only if the Pest rules
prove insufficient for layered dependency direction.

## Consequences

### Positive

- The boundary violation that normally kills a modular monolith — "just query the other
  module's table, it's one join" — fails the build instead of shipping.
- Because every operation is an Action with its own permission check and DTO, the later
  additions the spec anticipates (an admin/integration API in a future version, AI features
  calling the same operations, spec §6.14) need no refactor: they become new callers.
- Testability: an Action is testable without HTTP, without Filament, and without a browser.
- Onboarding: every module looks the same, so knowing one module means knowing all of them.

### Negative / costs

- More ceremony per feature: a DTO and an Action even for a small change. Accepted
  deliberately — the cost is paid once per operation, the benefit recurs at every later call
  site.
- Event-based communication makes some flows indirect: reading "what happens when an order is
  paid" means reading the listeners, not a linear function. Mitigated by documenting each
  module's published events and their subscribers in the module README.
- Eventual consistency between modules where an event is queued. This is deliberate for side
  effects (email, indexing, webhooks) and forbidden for money and stock, which stay inside one
  transaction.
- Composer path repositories add a `composer update` step when a module's dependencies change.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Contract interfaces become a dumping ground that re-couples modules | Contracts are reviewed as API changes; a contract that exposes a model or a query builder is rejected in review |
| A queued listener fails and a consequence is silently lost | Transactional outbox for anything with an external effect; failed jobs alert; listeners are idempotent |
| Architecture tests get skipped under deadline pressure | They are part of the CI required checks on protected `main`, not an optional suite |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Plain Laravel layout (`app/Models`, `app/Services`) | No boundaries to enforce; at 20 domains it becomes the WooCommerce-scale tangle this project is replacing. |
| Separate Composer packages in separate repositories | Real isolation, but versioning 20 internal packages and coordinating releases across them is a heavy tax for a single deployable app. Path repositories keep the option open. |
| Microservices | Explicitly a non-goal (spec §1.5), and it would turn one transaction over stock and payment into a distributed saga for no current benefit. |
| Repository pattern over Eloquent | Extra indirection with no payoff here; Eloquent already parameterises queries, and the module boundary — not a repository interface — is what protects the domain. |
