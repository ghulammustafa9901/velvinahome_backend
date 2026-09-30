# Architecture Decision Records

One Markdown file per significant decision (spec §12.4). An ADR is never edited after it is
accepted — it is **superseded** by a newer ADR, which keeps the reasoning history intact.

## Index

| ADR | Title | Status |
|---|---|---|
| [0001](0001-multi-database-tenancy-with-stancl-tenancy.md) | Multi-database tenancy with stancl/tenancy | Accepted |
| [0002](0002-api-host-strategy.md) | API host strategy: BFF on the brand domain plus `api.<brand>` | Accepted |
| [0003](0003-bff-authentication-pattern.md) | Customer authentication through a Next.js BFF | Accepted |
| [0004](0004-object-storage-cloudflare-r2.md) | Cloudflare R2 with per-tenant prefixes | Accepted |
| [0005](0005-search-typesense-per-tenant-collections.md) | Typesense with per-tenant collections, queried server-side | Accepted |
| [0006](0006-category-tree-closure-table.md) | Category tree as a closure table rather than `ltree` | Accepted |
| [0007](0007-module-structure-and-layering.md) | Module internal structure and enforced layering | Accepted |
| [0008](0008-identifier-and-money-representation.md) | ULID public identifiers, integer minor-unit money | Accepted |
| [0009](0009-local-development-environment-on-windows.md) | Local development environment on Windows, without Docker | Accepted |
| [0010](0010-cod-stock-commitment-at-order-placement.md) | COD stock commitment at order placement | Accepted |
| [0011](0011-module-autoloading-through-root-psr4.md) | Module autoloading through the root composer.json | Accepted |
| [0012](0012-tenant-scoping-of-cache-sessions-and-framework-singletons.md) | Tenant scoping of cache, sessions and framework singletons | Accepted |
| [0013](0013-owner-identity-without-credentials-and-handoff-tokens.md) | Company owner identity without credentials; hand-off tokens | Accepted |
| [0014](0014-search-infrastructure-typesense-30.md) | Typesense 30 specifics — synonym sets, SKU tokens, local runtime | Accepted |
| [0015](0015-order-payment-and-checkout-mechanics.md) | Order, payment and checkout mechanics for launch (COD + manual transfer) | Accepted |
| [0016](0016-api-contract-tooling-and-v1-freeze.md) | API contract tooling and the v1 freeze | Accepted |

## Deferred — ADRs to write when the decision is reached

| Topic | Blocked on | Phase |
|---|---|---|
| PostgreSQL hosting (self-managed on the VPS vs managed service) — weigh point-in-time recovery against per-tenant database count | owner budget decision | Phase 7 |
| Next.js hosting (self-hosted Node vs managed platform) — weigh per-tenant cost | owner decision | Phase 5 |
| Transactional email provider (SES / Postmark / Resend) — mail already goes through Laravel's mailer via the outbox (ADR-0015), so this is configuration | owner decision, spec §16.3 | before launch |
| First online payment gateway (default PayFast) — owner chose COD + manual transfer for launch; the adapter contract and webhook intake exist (ADR-0015) | owner decision, spec §16.2 | post-launch |
| Direct browser-to-Typesense querying with scoped search-only keys | measured need after Phase 5 | post-launch |
| Shared stock across brands of one company (explicit sync, never a cross-database query) | a real client requirement | post-v1 |

## Template

```markdown
# ADR-NNNN: <title>

- **Status:** Proposed | Accepted | Superseded by ADR-NNNN
- **Date:** YYYY-MM-DD
- **Spec reference:** §N.N (FINAL | DEFAULT | YOUR CALL)

## Context
What forces are at play? What constraints does the spec impose?

## Decision
What we are doing, stated plainly.

## Consequences
### Positive
### Negative / costs
### Risks and how they are mitigated

## Alternatives considered
Each with the reason it was not chosen.
```
