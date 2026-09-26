# Module Map & Module Internal Structure

The Laravel app is a **modular monolith** (FINAL — spec §3.3): one deployable application
with strictly bounded domain modules under `modules/`.

## 1. Module map (FINAL)

| Module | Responsibility | Depends on (contracts only) |
|---|---|---|
| `Platform` | Landlord concerns: companies, brands/tenants, domains, plans & feature flags, provisioning, platform users | — (landlord DB only) |
| `Identity` | Staff users, customers, authentication, roles, permissions, 2FA, login audit | — |
| `Settings` | Tenant settings (store info, currency, timezone, tax mode, feature toggles) | — |
| `Catalog` | Products, variants, attributes, categories, brands, collections, media | `Settings`, `Seo` |
| `Pricing` | Prices, compare-at prices, promotions, coupons, cart pricing pipeline | `Catalog`, `Settings` |
| `Inventory` | Locations, stock levels, movements ledger, reservations, purchasing, transfers, counts | `Catalog` |
| `Cart` | Guest + customer carts, cart merge | `Catalog`, `Pricing`, `Inventory` |
| `Checkout` | Checkout orchestration, address, shipping selection, payment initiation | `Cart`, `Shipping`, `Tax`, `Payments`, `Inventory`, `Orders` |
| `Orders` | Orders, order item snapshots, state machine, fulfillment, returns | `Inventory`, `Payments`, `Shipping`, `Customers` |
| `Payments` | Payment attempts, transaction ledger, gateway adapters, webhooks, reconciliation | `Orders`, `Settings` |
| `Shipping` | Zones, methods, rates, shipments, courier adapters | `Settings`, `Orders` |
| `Tax` | Tax classes, rates, inclusive/exclusive calculation | `Settings` |
| `Customers` | Customer profiles, addresses, groups | `Identity` |
| `Content` | Pages, page-builder sections, menus, blog, custom content types | `Seo`, `Catalog` |
| `Seo` | Meta, slugs, redirects, sitemap data, robots settings | — |
| `Reviews` | Product reviews and ratings (moderated) | `Catalog`, `Orders`, `Customers` |
| `Wishlist` | Customer wishlists | `Catalog`, `Customers` |
| `Notifications` | Templates and channels (email first) | `Settings` |
| `Reporting` | Aggregated reporting tables and dashboards | reads own aggregates only |
| `Integrations` | Adapter contracts, outbound webhooks, outbox, integration logs | — |

`Support` is a thin shared kernel (base Action, base DTO, money value object, ULID trait,
result types). It contains **no domain rules** and depends on nothing.

### Dependency rules

- The dependency column lists **contract** dependencies only. A module may type-hint
  another module's published interface; it may never import another module's Eloquent
  models, migrations or internal services.
- Cycles are forbidden in the concrete direction. Where two modules would need each other
  (e.g. `Orders` ↔ `Inventory`), the lower-level module publishes a contract and the
  higher-level module reacts to **domain events** instead of calling back.
  Example (FINAL, spec §3.4): `Orders` never decrements stock. It emits `OrderPaid`;
  an `Inventory` listener converts the reservation into a sale.
- Only `Platform` touches the landlord connection. Every other module is tenant-only.

## 2. Internal structure of a module (YOUR CALL — decided here, applies to all)

Every module follows the same layout. Consistency is enforced by architecture tests.

```text
modules/Catalog/
  src/
    Actions/                 CreateProduct.php, PublishProduct.php, …  (business logic)
    Contracts/               interfaces this module publishes to other modules
    Data/                    DTOs (spatie/laravel-data): ProductData, VariantData
    Events/                  ProductPublished.php, ProductUpdated.php  (public)
    Listeners/               queued listeners for other modules' events
    Models/                  Eloquent models  (internal — never imported elsewhere)
    Policies/                authorisation policies, invoked from Actions
    Enums/                   ProductStatus.php, …
    Exceptions/              domain exceptions
    Queries/                 read-side query objects for listings/reports
    Support/                 module-internal helpers
    Filament/
      Resources/             admin resources (thin — call Actions)
      Pages/                 workflow pages (e.g. ReceivePurchaseOrder)
    Http/
      Controllers/V1/        Storefront API controllers (thin)
      Resources/             API JSON resources
      Requests/             (only where a DTO cannot cover it)
    Jobs/                    queued jobs
    Console/                 module Artisan commands
    Providers/               CatalogServiceProvider.php
  database/
    migrations/tenant/       tenant-database migrations
    factories/
    seeders/
  routes/
    api_v1.php
  tests/
    Unit/
    Feature/
  composer.json              PSR-4: Modules\Catalog\  (path repository, not published)
```

Notes:

- `Platform` additionally has `database/migrations/landlord/`. No other module may.
- A module's **public surface** is exactly: `Contracts/`, `Data/`, `Events/`, `Enums/`.
  Everything else is internal. Architecture tests assert nothing outside the module
  imports `Models/`, `Actions/` internals or `Support/` of another module.
- Modules are wired in via Composer path repositories and each module's service provider,
  which registers routes, migrations, policies, Filament components and event listeners.

## 3. Naming conventions (spec §12.1)

| Thing | Convention | Example |
|---|---|---|
| Action class | imperative verb phrase, no `Service` suffix | `ReserveStock`, `PlaceOrder`, `RefundPayment` |
| DTO | `<Noun>Data` | `CheckoutData`, `StockAdjustmentData` |
| Event | past tense | `OrderPaid`, `StockAdjusted` |
| Contract | capability noun | `PaymentGateway`, `CourierProvider`, `StockReader` |
| DB column | `snake_case` | `low_stock_threshold` |
| DB table | plural `snake_case` | `inventory_movements` |
| Enum | singular | `OrderStatus` |
| TS symbols | `camelCase` / `PascalCase` types | `productCard`, `ProductSummary` |

## 4. Architecture tests to write in Phase 1, Milestone 1

1. Actions: every class in `*/Actions/` is `final`, has a single public `handle`/`__invoke`,
   and no HTTP/Filament/Request type in its signature.
2. Boundaries: no class in `modules/X` imports `Modules\Y\Models\*`, `Modules\Y\Actions\*`
   or `Modules\Y\Support\*` for any `Y !== X`.
3. Thin callers: no class under `Http/Controllers`, `Filament`, `Console` or `Jobs`
   performs writes — they must call an Action.
4. Landlord isolation: only `Modules\Platform` references the landlord connection name.
5. Mass assignment: no occurrence of `$request->all()` / `$request->input()` passed to a
   model `create`/`update`/`fill`.
6. Strict types: every PHP file declares `strict_types=1`.
7. Money: no float type-hint or `float` cast in any class handling money fields.
