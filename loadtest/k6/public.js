// Public pages under load: home, status, robots, sitemap. Signed-out visitors, as from search and ads.
// k6 run -e BASE_URL=https://staging.teamtoteamreferrals.com loadtest/k6/public.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const PEAK = Number(__ENV.PEAK_VUS || 1000);

export const options = {
    stages: [
        { duration: __ENV.RAMP || '1m', target: PEAK },
        { duration: __ENV.HOLD || '3m', target: PEAK },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'], // under 1% errors
        http_req_duration: ['p(95)<500', 'p(99)<1200'], // 95% under half a second
    },
};

const pages = ['/', '/status', '/robots.txt', '/sitemap.xml', '/llms.txt'];

export default function () {
    const res = http.get(BASE + pages[Math.floor(Math.random() * pages.length)], { headers: { 'Accept-Encoding': 'gzip' } });
    check(res, { 'status 200': (r) => r.status === 200 });
    sleep(Math.random() * 2 + 1); // think time between clicks
}
