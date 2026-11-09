# Load tests (k6)

Run against staging or a production-like stack (`docker compose -f compose.prod.yaml up --build`), never against live production during business hours.

| Script | Simulates | Pass criteria |
|---|---|---|
| `k6/public.js` | Signed-out visitors on public pages (default peak 1,000 concurrent) | under 1% errors, p95 under 500 ms |
| `k6/portal.js` | Members signing in and moving around the portal (default peak 300) | under 1% errors, p95 under 600 ms |

```bash
k6 run -e BASE_URL=https://staging.example.com loadtest/k6/public.js
k6 run -e BASE_URL=https://staging.example.com -e PEAK_VUS=1000 -e HOLD=5m loadtest/k6/public.js
k6 run -e BASE_URL=... -e EMAIL=jordan@example.com -e PASSWORD='correct horse battery staple' loadtest/k6/portal.js
```

Shorter smoke run: `-e RAMP=20s -e HOLD=40s -e PEAK_VUS=100`.

Results to record per release: requests/second at peak, p95 and p99 latency, error rate, and CPU/memory of the web container. The full 50,000-user test runs in Sprint 53 on the launch infrastructure.
