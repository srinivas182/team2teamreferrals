# Load tests (k6)

Run against staging or a production-like stack (`docker compose -f compose.prod.yaml up --build`), never against live production. Results and the capacity plan: `docs/performance/load-test-2026-11.md`.

| Script | Simulates | Pass criteria |
|---|---|---|
| `k6/journeys.js` | **The main test.** Visitors browsing public pages and members working in the portal at the same time, with think time like real people (default 65% visitors, 35% members) | under 1% errors; p95 under 500 ms public, 600 ms full portal pages, 400 ms moving around the portal |
| `k6/public.js` | Signed-out visitors only (default peak 1,000 concurrent) | under 1% errors, p95 under 500 ms |
| `k6/portal.js` | One member signing in repeatedly (older smoke test) | under 1% errors, p95 under 600 ms |

## Before a run

1. Members to sign in as (each simulated member needs its own address, because sign-in limits are per email):
   `php artisan t2t:loadtest:seed --members=2000` (password `load-test-2026`; `--clean` removes them afterwards).
2. Let the load generators past the per-visitor rate limits (staging only; ignored in production):
   `LOAD_TEST_IPS=203.0.113.10,203.0.113.11` in the staging `.env`, then `php artisan optimize`.
3. Test the origin, not the CDN: point `BASE_URL` at the load balancer. Public pages are cached at the edge for five minutes in production, so the origin normally sees only a small share of public traffic; testing the origin directly is the worst case.

## Running

```bash
# Smoke run (about 2 minutes)
k6 run -e BASE_URL=https://staging.example.com -e PEAK_VUS=100 -e RAMP=30s -e HOLD=1m -e MEMBERS=2000 loadtest/k6/journeys.js

# A step: 1,000 concurrent people for 10 minutes
k6 run -e BASE_URL=https://staging.example.com -e PEAK_VUS=1000 -e RAMP=3m -e HOLD=10m -e MEMBERS=2000 loadtest/k6/journeys.js
```

Options: `PEAK_VUS` (people at the peak), `MEMBER_SHARE` (default 0.35), `MEMBERS` (seeded members), `THINK` (multiplies think time; 0.25 makes everyone four times as busy), `RAMP`, `HOLD`.

## The 50,000-person test

One load generator handles roughly 5,000–10,000 simulated people, so split the run across machines with k6's execution segments (or the k6 Kubernetes operator / Grafana Cloud k6):

```bash
# On each of 8 generators (i = 0..7), all started together:
k6 run --execution-segment "$i/8:$((i+1))/8" --execution-segment-sequence "0,1/8,2/8,3/8,4/8,5/8,6/8,7/8,1" \
  -e BASE_URL=https://staging.example.com -e PEAK_VUS=50000 -e RAMP=15m -e HOLD=20m -e MEMBERS=20000 loadtest/k6/journeys.js
```

Seed 20,000 members first, list every generator's address in `LOAD_TEST_IPS`, and watch the web, database and Redis dashboards during the hold.

Record per release: requests per second at peak, p95 and p99 per kind (public, portal, portal-nav), error rate, and CPU and memory of the web, database and Redis servers.
