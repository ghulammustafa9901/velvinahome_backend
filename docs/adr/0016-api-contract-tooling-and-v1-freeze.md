# ADR-0016: API contract tooling and the v1 freeze

- **Status:** Accepted; amended by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) — one store per installation, so tenant, brand and landlord passages below are history
- **Date:** 2026-09-27
- **Spec reference:** §6.1 (FINAL: OpenAPI generated from code, generated TS client), §13 Phase 4
  (exit: API v1 contract frozen)

## Context

The Storefront API is consumed by the Next.js storefront now and a mobile app later; mobile
apps cannot be force-updated, so v1 may only change additively. Scramble generates OpenAPI
from code, but inference alone produced a weak document: lists built with `array_map` had
untyped items, `(object)` casts became `string`, error bodies were described in Laravel's
default shape instead of our envelope, and nothing stopped the document from drifting.

## Decision

1. **The contract is a committed file**, `docs/api/openapi-v1.json`, canonical JSON (sorted
   keys, host-specific `servers` removed).
2. **`php artisan api:contract --check` runs in CI.** It regenerates the document from code and
   fails when it differs from the snapshot; it names every change that would break an
   existing client (removed operation/status/property, property no longer guaranteed, type
   change, new required input), and it refuses lists whose items are undescribed. After
   review, `--write` updates the snapshot in the same pull request.
3. **Shapes are declared where data is built**, not in a hand-written spec: precise array-shape
   `@return` types on presenters (checked by Larastan), mirrored as `@scramble-return` where
   inference cannot follow (`array_map`, loops); value objects with their own schema
   components (`Money`, `StringMap`, `JsonObject`); one `Error` component for every 4xx; a
   recursive `CategoryNode`; route-derived facts (bearer security, `X-Cart-Token`,
   `X-Order-Token`, `Idempotency-Key`, 404/409/422/429/403) added by an operation transformer.
4. **Contract tests validate real responses strictly** against the committed document (JSON
   Schema 2020-12 via opis/json-schema): objects with declared properties may not carry
   undeclared ones, so a field the client cannot see fails a test.
5. **The TypeScript client is generated** in `platform-web/packages/api-client` with
   openapi-typescript from a copy of the snapshot (`pnpm sync:api-contract`), wrapped by a
   thin openapi-fetch factory. CI regenerates it and fails on any diff; type-level tests assert
   what the storefront relies on (Money objects, the order secret, error codes).

## Consequences

- Every contract change is a visible diff of `openapi-v1.json`, reviewed like code.
- After the freeze, a breaking change fails CI with a named reason; the fix is to make it
  additive or to plan v2.
- `@scramble-return` duplicates the `@return` shape on a handful of presenter methods; Larastan
  checks the `@return`, and the contract tests catch a stale `@scramble-return`.
- Response shapes changed during Phase 4 before the freeze (cart `token` always present and
  nullable, quote lines carry `id`/`available_quantity`, rating distribution as a list, search
  facets always the same four keys). No client existed yet, so nothing broke.
