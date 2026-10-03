# Load test

Repeatable load test of the Storefront API: catalog, search, cart and checkout quote
(spec §13 Phase 7). Results and budgets: [docs/performance/load-test.md](../docs/performance/load-test.md).

| File | What |
|---|---|
| `generate-catalog.mjs` | Deterministic product CSV (seeded) in the `imports:csv` format |
| `storefront.js` | k6 script: `browse`, `search` and `buy` journeys, signed like the storefront server |
| `serve-production-like.ps1` | Local only: an API server with cached config/routes, opcache, debug off |
| `service-times.ps1` | Local only: median/p95 per endpoint, one request at a time |

## 1. Load a catalog (never on a real brand)

Use a brand nobody else depends on — locally that is `charlie` (alpha and bravo are used by
the storefront E2E suite); on staging a dedicated load-test brand.

```powershell
node loadtest/generate-catalog.mjs --products 5000 --categories home,cushions,table-linen,bedding,dining --out catalog.csv
php artisan imports:csv <tenant id> catalog.csv
php artisan search:reindex --tenant=<tenant id>
```

The SKUs carry the `LT-` prefix, so the products are easy to find and archive afterwards.

## 2. Run

```powershell
$env:SERVICE_AUTH_SECRET = '<the API's SERVICE_AUTH_SECRET>'
k6 run loadtest/storefront.js                                   # smoke: service time, 1 user per journey
k6 run -e PROFILE=load loadtest/storefront.js                   # 20 journeys/s for 5 minutes
k6 run -e PROFILE=load -e SCALE=2 loadtest/storefront.js        # twice that
k6 run -e JOURNEY=search loadtest/storefront.js                 # one journey only
k6 run -e BASE_URL=https://api.loadtest.example -e API_HOST= -e PROFILE=load loadtest/storefront.js
```

Without `SERVICE_AUTH_SECRET` every request is anonymous traffic from one IP and the API's
rate limits (correctly) answer 429 within seconds.

The run exits non-zero when a budget (k6 threshold) is missed, so it can gate a pipeline.

## Local numbers are service times, not capacity

On Windows `php -S` serves one request at a time, so the local `load` profile measures
queueing, not the platform. Locally, run the `smoke` profile against
`serve-production-like.ps1` to measure per-request service time and find hot spots. Capacity
(requests per second, saturation point, PgBouncer and Typesense sizing) is measured with the
`load` and `stress` profiles on staging, on the production hardware class — see the deploy
runbook.
