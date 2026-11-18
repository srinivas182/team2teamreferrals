# Load test and capacity plan — November 2026 (Sprint 53b)

**Summary.** At realistic use, one vCPU running the app serves about **50 requests a second within every latency
target**. The test found and fixed four problems that would have grown with the network (below). Serving
**50,000 people at once** needs roughly **100–130 vCPUs** of web servers behind the CDN; that estimate must be
confirmed on a production-sized staging environment with `loadtest/k6/journeys.js` (see `loadtest/README.md`).

## How it was measured

| | |
|---|---|
| Script | `loadtest/k6/journeys.js`: 65% visitors browsing public pages, 35% members signed in and moving around the portal (Inertia requests), with think time |
| App | Octane on FrankenPHP (4 workers), Redis for sessions and cache, server-side rendering on the same machine, production build |
| Machine | One container with **2 shared vCPUs**, running the load generator (k6 used about a quarter of a vCPU) and the database (SQLite in WAL mode) as well |
| Limits | Per-visitor rate limits lifted for the load generator (`LOAD_TEST_IPS`), as they would be on staging |

This is a small, crowded machine, so the absolute numbers are conservative; what matters is the cost per request and
that latency stays flat until the machine is full.

## Results

One request on an idle machine: public pages 11–22 ms, a full portal page about 20 ms, moving between portal pages
about 7 ms.

| Load | Requests/s | p95 public | p95 portal (full page) | p95 moving around the portal | Errors | Verdict |
|---|---|---|---|---|---|---|
| 20 people | 3 | 35 ms | 42 ms | 17 ms | 0% | well within targets |
| 120 busy people (think time ÷4) | 94 | 117 ms | 115 ms | 71 ms | 0% | **within targets** |
| 160 busy people | 116 | 507 ms | 726 ms | 219 ms | 0% | the machine is full; latency starts to climb |

Targets: p95 under 500 ms for public pages, 600 ms for full portal pages and 400 ms for moving around the portal,
under 1% errors. No request failed at any level.

## What the test found, and the fixes

| Problem | Effect | Fix |
|---|---|---|
| A network's page looked up the main city of every filled seat one by one | A network with 1,000 seats would have run over 1,000 queries per page view | One query per kind of territory, whatever the number of seats (`DirectoryUrls::mainCities`) |
| "Open places" looked up each candidate city's state and county, and each place's waiting list, one at a time | Up to about 150 queries per page | Three queries in all |
| Industry hub pages weren't cached at the CDN | Every visit reached the servers | Cached for five minutes like other public pages, refreshed as soon as a hub is published or unpublished |
| Logging of PHP deprecation notices was misconfigured (`LOG_DEPRECATIONS_CHANNEL=null`) | On PHP 8.4 and later, every notice also wrote an "unable to create logger" emergency entry | Fixed in `config/logging.php`; one PHP 8.5 deprecation in the directory fixed too |

Tests now check that a network page and its open places take the same number of queries however many seats exist
(`tests/Feature/Platform/LoadTestingTest.php`).

## Capacity for 50,000 people at once

At real think time, a visitor makes about 0.18 requests a second and a member about 0.25, so 50,000 people at once
(65% visitors) make about **10,000 requests a second**:

| | Requests/s | Reaches the servers |
|---|---|---|
| Public pages (≈ 32,500 visitors) | ≈ 5,900 | ≈ 600 when the CDN serves 90% from its cache |
| Member portal (≈ 17,500 members) | ≈ 4,400 | ≈ 4,400 (signed-in pages are never cached) |
| **Total at the servers** | | **≈ 5,000** |

At about 50 requests a second per vCPU that is **≈ 100 vCPUs, ≈ 130 with 30% headroom**: for example 16 web
servers with 8 vCPUs each running Octane and server-side rendering, behind the load balancer. Without the CDN (worst
case) it would be about twice that.

Around the web servers: MySQL with a primary (16 vCPUs) and two read replicas, Redis for sessions, cache and queues
(two nodes), and Horizon workers scaled on queue length. These, and the per-vCPU figure, are what the staging run must
confirm: this test used SQLite on the same machine, with no network between the app and its database.

## Next run

On staging sized like production: seed 20,000 members, run `journeys.js` in steps (1,000, 5,000, 20,000, then 50,000
people across eight load generators), and record the table above for each step with the database and Redis load.
