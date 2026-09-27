# ADR-0014: Typesense 30 specifics — synonym sets, SKU tokens, local runtime

- **Status:** Accepted (refines ADR-0005)
- **Date:** 2026-09-27
- **Spec reference:** §6.10 (FINAL: Typesense, per-tenant collections, synonyms, exact SKUs)

## Context

ADR-0005 was written against the Typesense API of per-collection synonyms. Building search in
Phase 2 on Typesense 30.2 surfaced three facts:

1. Typesense 30 **removed per-collection synonyms**: they are now named *synonym sets* that a
   collection references (`synonym_sets` in its schema). The official PHP client (5.0) does
   not cover synonym sets yet.
2. The test "a mistyped SKU must not match" failed while `token_separators: ['-', '/']` was
   set: `VH-CC-001` was split into tokens and a mistyped SKU still matched on the shared
   prefix tokens.
3. Typesense publishes no Windows build; the development machine has WSL2 (Ubuntu).

## Decision

- One synonym set per tenant, `{prefix}{tenant_id}_synonyms`, created before the tenant's
  collection and referenced by it. Synonyms live in the tenant's `search_synonyms` table
  (source of truth) and every change pushes the **complete** set through the REST API
  (`TypesenseSynonyms`), after commit — idempotent and self-healing.
- No custom token separators: a SKU is one token. SKUs keep `num_typos=0` and no prefix
  matching.
- Search results are re-read from PostgreSQL; the index decides relevance and facets only.
- Locally, Typesense 30.2 runs inside WSL2 (`~/typesense`, started by
  `devtools\start-services.ps1`) and is reached on `127.0.0.1:8108`. CI and servers use the
  official container/binary. The test suite uses the `test_` collection prefix.

## Consequences

- Positive: per-tenant synonyms work on the current engine; SKU search is exact.
- Negative: hyphenated words are no longer split for search ("hand-woven" matches
  "hand-woven", not "woven"). Acceptable; titles rarely depend on it and synonyms can
  cover real cases.
- Risk: the REST call for synonym sets must be revisited when the PHP client adds them.
