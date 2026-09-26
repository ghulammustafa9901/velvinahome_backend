# ADR-0006: Category tree as a closure table rather than `ltree`

- **Status:** Accepted
- **Date:** 2026-09-26
- **Spec reference:** §6.3 (**YOUR CALL**, ADR required), §6.4

## Context

Categories are nested to unlimited depth (spec §6.4). The read patterns that matter:

1. Render the navigation tree (whole tree, cached).
2. List products in a category **including all descendants** — the hot storefront query.
3. Show breadcrumbs for a category (all ancestors, in order).
4. Move a subtree in the admin (rare, but must not corrupt anything).
5. Count products per category for facets.

Spec §6.3 leaves the choice between PostgreSQL's `ltree` extension and a closure table, and
requires the decision to be recorded here.

## Decision

Use a **closure table**.

```sql
CREATE TABLE categories (
  id         BIGSERIAL PRIMARY KEY,
  ulid       CHAR(26) NOT NULL UNIQUE,
  parent_id  BIGINT REFERENCES categories(id) ON DELETE RESTRICT,
  name       TEXT NOT NULL,
  slug       TEXT NOT NULL,
  position   INTEGER NOT NULL DEFAULT 0,
  is_visible BOOLEAN NOT NULL DEFAULT TRUE,
  -- timestamps, deleted_at
);
CREATE UNIQUE INDEX categories_slug_unique ON categories (slug) WHERE deleted_at IS NULL;

CREATE TABLE category_closure (
  ancestor_id   BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  descendant_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  depth         INTEGER NOT NULL CHECK (depth >= 0),
  PRIMARY KEY (ancestor_id, descendant_id)
);
CREATE INDEX category_closure_descendant_idx ON category_closure (descendant_id, depth);
```

Every category has a self-row at `depth = 0`. Descendant listing becomes a plain join:

```sql
SELECT p.* FROM products p
JOIN product_category pc ON pc.product_id = p.id
JOIN category_closure  cc ON cc.descendant_id = pc.category_id
WHERE cc.ancestor_id = :categoryId AND p.status = 'active' AND p.deleted_at IS NULL;
```

The closure table is maintained **only** by the `Catalog` module's Actions
(`CreateCategory`, `MoveCategory`, `DeleteCategory`), inside the same transaction as the
`categories` write. A nightly consistency check recomputes the closure from `parent_id` and
alerts on any mismatch, the same way the inventory reconciliation job works.

## Consequences

### Positive

- The hot query (products in a category and all descendants) is a single indexed join with no
  recursion and no extension-specific operators — it is easy to `EXPLAIN ANALYZE`, easy to
  index, and predictable as catalogs grow.
- Breadcrumbs are one query: `WHERE descendant_id = :id ORDER BY depth DESC`.
- **No PostgreSQL extension dependency.** `ltree` is a contrib module, and `CREATE EXTENSION`
  requires elevated privileges. In this platform databases are created by a provisioner role
  and the application role deliberately has no DDL rights, and a managed PostgreSQL service
  may or may not offer `ltree`. Avoiding the extension keeps `tenant:create` portable across
  every hosting option still open in the deferred hosting ADR.
- Plain integer keys and plain joins work naturally with Eloquent, so no raw SQL leaks into
  the query layer.

### Negative / costs

- The closure table is derived data that must be kept correct: a subtree move rewrites
  `O(ancestors × descendants)` rows. Category trees are small and moves are rare, so this is
  cheap in practice, but it is genuine duplicated state.
- More storage than a single `path` column. Negligible at realistic category counts
  (hundreds, not millions).
- Correctness depends on all writes going through the Actions. This is already a FINAL
  layering rule, it is enforced by architecture tests, and the nightly check catches any
  violation that slips through.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Closure rows drift from `parent_id` | Single transaction for both writes; nightly recompute-and-compare with an alert; a rebuild command exists |
| A cycle is introduced by a bad move | `MoveCategory` rejects a move into the category's own descendant set before writing; unit-tested |
| A direct SQL edit corrupts the tree | The application role holds no DDL; staff never touch SQL; the rebuild command is the documented repair |

## Alternatives considered

| Alternative | Why not |
|---|---|
| `ltree` | Elegant and fast, with `@>` descendant queries, but it adds a contrib-extension dependency to every tenant database created by a least-privilege provisioner, constrains hosting choices, needs raw SQL or a custom Eloquent grammar, and requires rewriting descendant paths on a move anyway. The upside did not pay for the portability loss. |
| Recursive CTE over `parent_id` only | No derived state to maintain, but the hot storefront query becomes a recursive CTE, which is harder to index and slower on the path that matters most. |
| Nested sets (left/right bounds) | Reads are fast, but every insert rewrites a large slice of the table and concurrent writes are error-prone. Wrong trade for an admin-edited tree. |
| Materialised path string (`/1/4/9/`) | Simple, but descendant queries need `LIKE 'prefix%'`, ancestor queries need string parsing, and renames cascade across rows. Weaker integrity than real foreign keys. |
