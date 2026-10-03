# Phase 0 Checkpoint Report

> **Historical record. Superseded in part by [plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md) and
> [ADR-0020](adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Date:** 2026-09-26
**Phase:** 0 — Planning & Architecture
**Status:** deliverables complete, **awaiting owner approval before any application code is written**

Per spec §17 and §0.2 rule 5, work stops here until the architecture and ERD are approved.

---

## 1. What was built

| Deliverable (spec §13 Phase 0, §17) | Where |
|---|---|
| Repositories created | `platform-api/`, `platform-web/` (git initialised, no remote yet) |
| Docker Compose environment | [docker-compose.yml](../docker-compose.yml) — PostgreSQL 17, PgBouncer, Redis, Typesense, Mailpit, MinIO, plus [role bootstrap SQL](../docker/postgres/01-roles.sql) |
| CI skeleton | [.github/workflows/ci.yml](../.github/workflows/ci.yml) (api), [platform-web CI](../../platform-web/.github/workflows/ci.yml) |
| Architecture overview | [docs/architecture/overview.md](architecture/overview.md) |
| Tenancy design | [docs/architecture/tenancy.md](architecture/tenancy.md) |
| Module map + internal structure | [docs/architecture/modules.md](architecture/modules.md) |
| ERD (landlord + tenant core) | [docs/architecture/data-model.md](architecture/data-model.md) |
| PostgreSQL role/privilege design | [docs/architecture/database-roles.md](architecture/database-roles.md) |
| API conventions | [docs/architecture/api-conventions.md](architecture/api-conventions.md) |
| Initial ADRs | [docs/adr/](adr/) — 0001–0008 |
| Phase 1 plan with tests | [docs/plans/phase-1.md](plans/phase-1.md) |
| Progress tracking | [docs/PROGRESS.md](PROGRESS.md) |

No application code, no migrations, no dependencies installed — that is Phase 1.

---

## 2. My understanding of the specification

**The product.** A managed-service platform, not self-serve SaaS. The Platform Owner's team
builds each client's storefront on a shared core; the client's staff operate their store
through an admin panel; clients never modify the system. It replaces per-client
WordPress/WooCommerce builds, and the value it sells over WooCommerce is (a) real data
isolation, (b) first-class inventory, (c) SEO and mobile performance, (d) one core improved
once for everyone.

**The tenancy model, which everything else follows from.** One tenant = one brand = one
website = one PostgreSQL database. A company is a billing and contracting grouping in the
landlord database and holds no brand data. A company with three brands has three databases,
three admins, three domains, and separate inventory in each. The landlord database holds only
registry and platform data, never a tenant's customers, orders, products or payments.

**The build order matters as much as the content.** Tenancy, auth, queues and caching are
foundations built in Phase 1, before a single product table exists, because retrofitting
tenancy is the rebuild this project exists to avoid. I read the phase order as non-negotiable
sequencing, not a suggestion.

**The layering contract.** All business logic lives in Actions; controllers, Filament,
commands, jobs and webhooks are thin callers; modules talk only through contracts and domain
events; boundaries are enforced by tests in CI, not by good intentions. The storefront never
computes a business value — price, discount, shipping, tax, total and availability all come
from the backend.

**Where the money and stock rules are absolute.** Inventory is an append-only ledger with
database-enforced immutability; `on_hand` changes only through Inventory Actions inside a
transaction that also writes a movement; overselling must be impossible unless backorders are
explicitly enabled. Money is integer minor units, never a float. A browser redirect is never
proof of payment — only a verified webhook plus, where available, a server-side status query.

**The market shapes the design.** Pakistan first: PKR and Asia/Karachi as tenant settings not
constants, **COD as the dominant payment method** (so COD confirmation and RTO are core order
states, not edge cases), local gateways and couriers behind adapters, mobile-first performance
as the primary target, i18n-ready for Urdu/RTL later.

**What is deliberately not being built:** self-serve signup, marketplace, microservices,
GraphQL, the mobile app itself (the API must be ready for it), POS, shopper subscriptions, and
AI features (architecture ready, nothing built).

---

## 3. Concerns and contradictions

Raised here as §0.1 requires. **Nothing below has been implemented against the spec** — I have
built to the spec as written and am flagging these for your decision.

### 3.1 Blocker — the development machine cannot run this stack

This is the only item that stops Phase 1 from starting.

| Spec requirement | This machine |
|---|---|
| PHP 8.4+ | PHP **8.3.33** |
| PostgreSQL 17+ | not installed (no `psql`) |
| Docker Compose for local services | **Docker not installed** |
| pnpm + Turborepo | **pnpm not installed** (npm 11 present) |
| Redis, Typesense, MinIO, Mailpit | not installed |
| "Explicitly not used: MySQL/MariaDB, shared hosting" | running under **XAMPP** (Apache + MySQL) |

The Docker Compose file I wrote is correct and complete, but it cannot be started here. Phase 1
Milestone 1.1's exit condition — "the app boots, migrations run, tests pass" — is unverifiable
until this is fixed.

**Options, in the order I would choose them:**

1. **Install Docker Desktop + PHP 8.4 on this Windows machine.** Keeps the spec exactly as
   written. XAMPP can stay installed; it simply goes unused for this project. Lowest deviation,
   and the Compose file already works.
2. **Develop in WSL2** (Ubuntu) with Docker inside it. Closest to the production Linux
   environment, and it avoids Windows filesystem performance problems with large PHP
   dependency trees. My recommendation if you are comfortable with WSL.
3. **A Linux dev VPS** as the development environment, with local editing over SSH. Best
   parity, extra cost, slower feedback loop.
4. Install PostgreSQL 17, Redis and Typesense natively on Windows without Docker. Workable but
   fragile, and it diverges from the "one command to set up" requirement in spec §10.

**I need your decision on this before starting Phase 1**, because it determines whether the
Phase 1 plan's verification steps mean anything. Whichever you pick, it becomes an ADR.

Also worth stating plainly: the project directory currently sits in `C:\xampp\htdocs\`. Nothing
in this platform is ever served by Apache from `htdocs` — Laravel serves through its own
`public/` directory and the storefront is a Node application. Moving the repositories somewhere
like `C:\projects\` would avoid confusion later, but that is cosmetic and can wait.

### 3.2 Contradiction — reservation expiry versus COD confirmation

This is the most consequential design tension I found.

Spec §6.5 rule 3: reservations "are created at checkout/payment initiation with an expiry;
converted to a sale on payment success **or COD confirmation**; released on failure,
cancellation, or expiry (scheduled sweep)."

Spec §6.4 and §1.3 make COD dominant, and COD confirmation is a **human phone call** that can
take hours or a day. If a reservation expires before staff reach the customer, the stock is
released while a live, unconfirmed COD order still exists — and the same unit gets sold twice.
Making the reservation expiry long enough to cover confirmation instead lets abandoned
checkouts lock up stock for a day.

**My recommendation, for Phase 3:** for COD orders, commit the stock at **order placement**,
not at COD confirmation — convert the reservation to a `sale` movement when the order is
created, and treat "unreachable", "fake" and RTO outcomes as an explicit `return` movement that
puts stock back with a reason. Short-lived expiring reservations then apply only to the
pre-placement checkout window and to online payment initiation, which is what they are good at.
This keeps overselling impossible, keeps the ledger honest about where units actually are, and
makes RTO visible as a real inventory event rather than a silent release. It needs your
agreement because it changes what "confirmed" means for stock.

### 3.3 Tension — the landlord DB must hold no tenant data, yet must report across brands

Spec §4.1: the landlord database "never stores any tenant's customer, order, product, or
payment data." Spec §4.1a and §4.9: cross-brand and cross-tenant reporting comes from a
scheduled aggregation **into the landlord DB**, limited to non-sensitive summary figures.

These are compatible only if "aggregate" is defined strictly, and that definition needs to be
written down before someone helpfully adds a column. My reading, which I have built to:
`tenant_metrics_daily` may hold per-tenant-per-day **counts and totals only** — orders count,
gross sales amount, optionally sessions. No order rows, no customer identifiers, no product
identifiers, no line items, nothing per-person. A test asserts the landlord schema contains no
table capable of holding tenant entity rows. Please confirm that is the line you want, because
the first request for "which products sell best across my brands" will push against it.

### 3.4 Concern — `tenant:create` needs privileges that some managed PostgreSQL services restrict

Provisioning a tenant requires `CREATEDB` and `CREATEROLE`. Several managed PostgreSQL products
restrict role creation, database ownership transfer, or `ALTER DEFAULT PRIVILEGES` for
non-superusers, and some price per database in a way that makes 50 databases painful.

This does not change any Phase 1 code — it changes the hosting decision deferred to Phase 7
(spec §10). I have written the provisioning SQL to use only standard, non-superuser operations,
but **the hosting shortlist must be validated against `tenant:create` before you commit to a
provider**, not after. I will add that as an explicit acceptance test in the hosting ADR.

### 3.5 Concern — PgBouncer transaction pooling constrains what the application may do

Transaction pooling is the right mode for many databases with modest concurrency each, but it
forbids session-level state: no session `SET`, no advisory locks held across statements, no
`LISTEN`/`NOTIFY`, and care with prepared-statement emulation. Row locks inside a transaction
are unaffected, which is what the inventory design actually needs.

Mitigation already in the plan: PgBouncer runs in the **local** Compose file from day one, so a
session-state assumption breaks on a developer's machine rather than in production. Migrations
connect directly, bypassing the pooler, because transactional DDL needs a session connection.

### 3.6 Concern — Typesense is RAM-bound, and tenant count multiplies indexes

Typesense holds indexes in memory. At roughly 3 collections per tenant and 50 tenants, node
sizing becomes a function of *total* catalog size across every brand, not per brand. No
architectural change is needed, but capacity planning belongs in Phase 7's load test and in the
per-tenant onboarding checklist (a new tenant with a 100k-product catalog is a sizing event, not
a routine provision).

### 3.7 Business concern — mandatory staff 2FA

Spec §6.2 makes 2FA mandatory for all tenant staff. That is the right default and I have
planned it as a hard gate. Be aware of the operational reality: client staff in a warehouse or
shop may share a workstation or lack a smartphone for TOTP, and the first onboarding will
surface it. Options to decide at Phase 1 Milestone 1.5: TOTP-only (as specified), TOTP plus
printed recovery codes (already planned), or an owner-approved per-tenant exemption for
specific low-privilege roles — which I would rather not build unless you ask, because "2FA
except when inconvenient" tends to become "no 2FA".

### 3.8 Minor — spec cross-reference and scope wording

1. **§1.5 points at §6.13 for AI readiness; the AI readiness section is §6.14** (§6.13 is SEO
   data). Just a stale cross-reference — no design impact.
2. **§14 MVP vs §15 Definition of Done.** MVP is "end of Phase 5 plus essential Phase 7
   hardening", but several §15 DoD items are completed in Phase 6 (importers, reporting that
   matches raw data). I read §15 as the v1 *platform* definition and §14 as the first *tenant
   launch* bar, and have planned accordingly. Flagging so the two are not mistaken for the same
   gate at Phase 5.
3. **`{{PLATFORM_NAME}}` is still a placeholder** (§16.1). It blocks the landlord panel
   hostname, mail from-names and the repository organisation naming, but nothing before Phase 1
   Milestone 1.6.

---

## 4. Decisions I made under "YOUR CALL"

Each is recorded as an ADR with alternatives and costs.

| Decision | Choice | ADR |
|---|---|---|
| API host strategy | BFF on the brand domain for browsers, plus `api.<brand>` for server-to-server, webhooks and the future mobile app | [0002](adr/0002-api-host-strategy.md) |
| Customer auth | Sanctum token held server-side by the Next.js BFF in an httpOnly cookie; token never in browser JS | [0003](adr/0003-bff-authentication-pattern.md) |
| Object storage | Cloudflare R2, one bucket per environment, per-tenant key prefix; per-tenant bucket available as configuration | [0004](adr/0004-object-storage-cloudflare-r2.md) |
| Search | Typesense per-tenant collections, queried through Laravel; exact matching on SKU, typo tolerance elsewhere | [0005](adr/0005-search-typesense-per-tenant-collections.md) |
| Category tree | Closure table, not `ltree` — avoids a contrib-extension dependency in every provisioned tenant database | [0006](adr/0006-category-tree-closure-table.md) |
| Module internal layout | Identical structure per module, four-directory public surface, Composer path repositories | [0007](adr/0007-module-structure-and-layering.md) |
| Identifiers and money | `BIGSERIAL` internal + ULID public; `BIGINT` minor units + currency, basis points for rates | [0008](adr/0008-identifier-and-money-representation.md) |

---

## 5. What I need from you to proceed

**Blocking:**

1. **Development environment decision** (§3.1). Phase 1 cannot be verified without it.
2. **Approval of the architecture docs and the ERD** — the Phase 0 exit criterion.

**Needed soon, not blocking today:**

3. The COD reservation model (§3.2) — decide before Phase 3, but tell me now if you disagree,
   because it affects how Phase 2's inventory Actions are shaped.
4. Confirmation of the landlord aggregate boundary (§3.3).
5. Platform name and platform domain (§16.1) — needed by Phase 1 Milestone 1.6.
6. Whether VOG and SIX are one brand or two tenants (§16.5) — needed for demo seeding, and it
   is a useful early test of the multi-brand path either way.
7. Initial topology per tenant (§16.6) — shared or dedicated for the first brands.

**On approval I will start Phase 1 Milestone 1.1** (Laravel app, module skeleton, quality
gates) and report at each milestone, stopping at the Phase 1 checkpoint.
