# ADR-0005: Typesense with per-tenant collections, queried server-side

- **Status:** Superseded by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) (single-store application, 2026-10-03)
- **Date:** 2026-09-26
- **Spec reference:** §6.10 (FINAL), §4.4

## Context

Search is a core storefront feature for a Pakistani audience: typo tolerance matters, and so
do Roman Urdu spelling variants of the same product type. The spec fixes the engine
(Typesense via Laravel Scout) and the isolation model (per-tenant collections, no shared
collection). What this ADR records is the collection naming, document shape, indexing
lifecycle, and the decision to query through Laravel rather than from the browser.

## Decision

### Collections

One collection per tenant per searchable entity, named `{tenant_id}_{entity}`:

```text
01J8ZC0K9M2QF3T4W5X6Y7Z8AB_products
01J8ZC0K9M2QF3T4W5X6Y7Z8AB_categories     (Phase 2+, for navigation search)
01J8ZC0K9M2QF3T4W5X6Y7Z8AB_pages         (Phase 5+, for site search)
```

Collections are created during `tenant:create` and deleted by `tenant:delete`. A missing
collection is a provisioning failure, not something a request silently tolerates.

### Product document shape

```jsonc
{
  "id": "01J8ZC…",              // product ULID
  "title": "Cotton Cushion Cover",
  "skus": ["VH-CC-001-BEIGE", "VH-CC-001-GREY"],
  "brand": "Velvina Home",
  "category_path": ["Home", "Living", "Cushions"],
  "category_ids": ["01J…", "01J…"],
  "tags": ["cushion", "cover", "cotton"],
  "attributes": { "color": ["Beige", "Grey"], "material": ["Cotton"] },
  "price_min": 249900, "price_max": 299900,    // minor units, for range facets and sorting
  "in_stock": true,
  "rating": 4.6, "reviews_count": 23,
  "sales_rank": 142,                            // popularity sorting
  "created_at": 1758787200,                     // int64 for sorting
  "image_url": "https://cdn…/…webp",
  "slug": "cotton-cushion-cover"
}
```

### Search behaviour

- **Typo tolerance on** `title`, `brand`, `category_path`, `tags` — up to 2 typos for longer
  words, fewer for short ones.
- **`skus` is matched exactly: no typo tolerance on SKU or barcode.** A one-character typo on
  a SKU must not return a different product; staff and customers use SKUs as exact keys.
- **Prefix search on** `title` for as-you-type suggestions.
- **Facets:** category, brand, attribute values, price range, `in_stock`, rating.
- **Sorts:** relevance, `price_min` asc/desc, `created_at` desc (newest), `sales_rank`
  (best-selling).
- **Synonyms are per tenant**, managed in the tenant admin (`search_synonyms` table), applied
  to that tenant's collection only — this is where Roman Urdu variants live.
- **Only `active`, published products are indexed.** Draft and archived products are removed
  from the index, not merely filtered at query time.

### Indexing lifecycle

- Scout indexing runs in **queued jobs on the `search` queue, dispatched after the database
  transaction commits**, so a rolled-back product save never leaves a phantom in the index.
- `search:reindex --tenant=<id>` rebuilds one tenant; the default rebuilds all, tenant by
  tenant, using an alias swap so search stays available during a rebuild.
- **PostgreSQL remains the source of truth.** Search results are re-validated for price and
  stock when an item is added to the cart, so a stale index can never sell at a stale price.

### Querying path

The storefront queries search **through the Laravel API** (`GET /api/v1/search`). Direct
browser-to-Typesense queries with scoped, search-only, tenant-restricted keys remain an
optional later optimisation, and require their own ADR (spec §6.10).

## Consequences

### Positive

- Isolation is structural: a query against tenant B's collection cannot see tenant A's
  documents, and isolation test #5 asserts it.
- Per-tenant synonyms, stopwords and ranking tuning are possible without affecting any other
  brand — which is what makes local-language search quality achievable per client.
- Querying through Laravel means the search key never reaches the browser, facets can be
  enriched with server-side data (stock, tenant pricing rules), and rate limiting applies.
- A single tenant's reindex never touches another tenant's index.

### Negative / costs

- Collection count grows at roughly 3 per tenant. At 50 tenants that is ~150 collections,
  which Typesense handles, but memory is the planning constraint: Typesense holds indexes in
  RAM, so node sizing is a function of total catalog size across all tenants. Tracked as a
  Phase 7 load-test item.
- Every search request costs a Laravel hop, adding latency versus browser-direct querying.
  Acceptable for v1; the direct-query optimisation exists as a documented escape hatch if
  measurements justify it.
- A `tenant:create` failure after collection creation must be re-runnable without duplicating
  collections — covered by the idempotent provisioning steps.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Index drifts from the database | Reindex command per tenant; a scheduled consistency check compares document counts against the active-product count and alerts |
| Typesense node loss | Indexes are rebuildable from PostgreSQL by definition; a restore drill item covers rebuilding all tenants' collections |
| Stale price or stock sells wrongly | Cart add re-validates price and stock against the database, always |
| Memory exhaustion as tenants grow | Per-tenant document counts monitored; node sizing reviewed in Phase 7 load tests |

## Alternatives considered

| Alternative | Why not |
|---|---|
| One shared collection with a `tenant_id` filter | Forbidden by spec §6.10, and one forgotten filter shows another brand's catalog. Also prevents per-tenant synonyms and ranking. |
| PostgreSQL full-text search (`tsvector`) | No comparable typo tolerance, weak faceting, and tuning relevance becomes a project of its own. Good enough for admin-side lookups, not for a storefront. |
| Meilisearch / Algolia | Spec fixes Typesense (FINAL). Algolia would also put per-record pricing on a multi-tenant catalog. |
| Browser-direct Typesense queries in v1 | Needs scoped search-only keys per tenant and moves rate limiting to the edge; the spec explicitly defers this and requires its own ADR. |
