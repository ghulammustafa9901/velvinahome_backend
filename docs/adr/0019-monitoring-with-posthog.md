# ADR-0019: Monitoring with PostHog (instead of Sentry)

- **Status:** Accepted
- **Date:** 2026-10-01
- **Spec reference:** §9 (named Sentry), §8 (no secrets in the repository), owner decision 2026-10-01

## Context

Spec §9 asks for error tracking for both apps with release tracking, request ids across
Next.js and Laravel, uptime checks and alerting. The owner chose **PostHog (EU cloud)**: it
also gives storefront product analytics, which Sentry does not.

## Decision

- **API errors:** a reporter on Laravel's exception handler sends `$exception` events to the
  PostHog capture endpoint after the response (or immediately in console/queue), with a
  3-second timeout; failures to send are only logged. Payload: exception class, a scrubbed
  message (emails and long numbers masked; database errors reduced to their SQLSTATE), stack
  frames with app-relative paths, tenant id, request id, release, environment, HTTP method and
  route pattern. Never request bodies, query strings, headers, cookies or SQL. One report per
  error signature per minute.
- **Storefront:** browser errors and analytics through `posthog-js`; server errors through
  Next.js `onRequestError`. Privacy defaults: no person profiles, no session recording, no
  surveys, input values never captured, Do Not Track honoured, localStorage instead of cookies,
  URLs sent without query strings (they can carry tokens). Automated browsers are ignored by
  PostHog's bot filter, so test runs do not pollute analytics.
- **Request id:** the storefront middleware assigns one per request and forwards it to the API
  as `X-Request-Id`; the API logs it and returns it in error envelopes.
- **Health:** `GET /health` on the platform host — database, Redis, Typesense, queue backlog and
  failed jobs in the last hour; 503 when degraded; component detail only with
  `HEALTH_CHECK_TOKEN`. Uptime monitors and PostHog alerts are configured per environment.
- **Keys:** `POSTHOG_PROJECT_KEY` / `NEXT_PUBLIC_POSTHOG_KEY` (a public, write-only project key)
  live in environment files only; empty disables reporting.

## Consequences

- One tool for errors and analytics; errors are grouped by fingerprint in PostHog.
- Less automatic context than a Sentry SDK (breadcrumbs, source maps): stack frames are raw;
  source-map upload for the storefront can be added with the deploy pipeline.
- Storefront analytics is personal data in some jurisdictions; a per-brand consent banner may
  be needed before launch in such markets (a brand setting can gate `Analytics`).

## Alternatives considered

| Alternative | Why not |
|---|---|
| Sentry (spec default) | Owner chose PostHog; it would add a second tool for analytics |
| PostHog PHP / Node SDKs | The capture endpoint is enough; fewer dependencies and full control over what is sent |
