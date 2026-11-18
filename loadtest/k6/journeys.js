/*
 * Realistic load (Sprint 53b): visitors browsing public pages and members working in the portal at the same time,
 * with think time between steps like real people.
 *
 * - Visitors: home, the directory, a network, an industry hub, a city page, help, partners, the blog.
 * - Members: sign in once, then move around the portal the way the app does (Inertia requests, not full page loads),
 *   now and then reload a page or mark notifications read.
 *
 * Members come from `php artisan t2t:loadtest:seed --members=N` (loadtest1@loadtest.invalid …).
 *
 *   k6 run -e BASE_URL=https://staging.example.com -e PEAK_VUS=500 loadtest/k6/journeys.js
 *   k6 run -e BASE_URL=... -e PEAK_VUS=50000 -e RAMP=10m -e HOLD=20m -e MEMBERS=20000 loadtest/k6/journeys.js   (distributed: see README)
 *
 * Pass criteria (the targets in docs/performance): under 1% errors; p95 under 500 ms for public pages, 600 ms for
 * full portal pages and 400 ms for moving around the portal.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const PEAK = Number(__ENV.PEAK_VUS || 200);
const MEMBER_SHARE = Number(__ENV.MEMBER_SHARE || 0.35);
const MEMBERS = Number(__ENV.MEMBERS || 200);
const PASSWORD = __ENV.PASSWORD || 'load-test-2026';
const THINK = Number(__ENV.THINK || 1); // multiply think time (0.5 = twice as busy)

const stages = (vus) => [
    { duration: __ENV.RAMP || '1m', target: vus },
    { duration: __ENV.HOLD || '3m', target: vus },
    { duration: '30s', target: 0 },
];

export const options = {
    scenarios: {
        visitors: {
            executor: 'ramping-vus',
            exec: 'visitor',
            stages: stages(Math.max(1, Math.round(PEAK * (1 - MEMBER_SHARE)))),
            gracefulRampDown: '30s',
        },
        members: { executor: 'ramping-vus', exec: 'member', stages: stages(Math.max(1, Math.round(PEAK * MEMBER_SHARE))), gracefulRampDown: '30s' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
        'http_req_duration{kind:public}': ['p(95)<500'],
        'http_req_duration{kind:portal}': ['p(95)<600'],
        'http_req_duration{kind:portal-nav}': ['p(95)<400'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const PUBLIC = [
    '/',
    '/groups',
    '/groups/solar-installers-network',
    '/industries/solar',
    '/industries/solar/tx/austin',
    '/find-a-pro',
    '/help',
    '/help/how-to-claim-a-seat',
    '/partners',
    '/blog',
    '/press',
];
const PORTAL = ['/app/inbox', '/app/pipeline', '/app/sent', '/app/notifications', '/app/earnings', '/app/groups', '/app/team', '/app/dashboard'];
const pick = (list) => list[Math.floor(Math.random() * list.length)];
const think = (min, max) => sleep((min + Math.random() * (max - min)) * THINK);
const headers = { 'Accept-Encoding': 'gzip, br', 'User-Agent': 'k6-load-test (t2t)' };

export function visitor() {
    // A short visit: arrive, look at two to four pages, leave.
    const steps = 2 + Math.floor(Math.random() * 3);
    for (let i = 0; i < steps; i++) {
        const r = http.get(BASE + (i === 0 ? pick(['/', '/', '/groups', '/industries/solar/tx/austin']) : pick(PUBLIC)), {
            headers,
            tags: { kind: 'public' },
        });
        check(r, { 'public page 200': (x) => x.status === 200 });
        think(3, 8);
    }
}

function xsrf(jar) {
    const c = jar.cookiesForURL(BASE)['XSRF-TOKEN'];
    return c ? decodeURIComponent(c[0]) : '';
}

/** The app's build version, from the page (Inertia requests must send it). */
function version(html) {
    const m = /&quot;version&quot;:&quot;([^&]*)&quot;|"version":"([^"]*)"/.exec(html || '');
    return m ? m[1] || m[2] || '' : '';
}

let signedIn = false;
let appVersion = '';

export function member() {
    const jar = http.cookieJar();
    if (!signedIn) {
        http.get(`${BASE}/login`, { headers, tags: { kind: 'auth' } });
        const email = `loadtest${((__VU - 1) % MEMBERS) + 1}@loadtest.invalid`;
        const r = http.post(
            `${BASE}/login`,
            { email, password: PASSWORD },
            { headers: { ...headers, 'X-XSRF-TOKEN': xsrf(jar) }, redirects: 0, tags: { kind: 'auth' } },
        );
        signedIn = check(r, { 'signed in': (x) => x.status === 302 && !String(x.headers.Location || '').includes('/login') });
        if (!signedIn) {
            think(5, 10);
            return;
        }
        const home = http.get(`${BASE}/app`, { headers, tags: { kind: 'portal' } });
        check(home, { 'portal home 200': (x) => x.status === 200 });
        appVersion = version(home.body);
        think(2, 5);
    }
    // Moving around the app: Inertia requests return the next page's data as JSON.
    for (let i = 0; i < 4; i++) {
        const path = pick(PORTAL);
        const r = http.get(BASE + path, {
            headers: {
                ...headers,
                'X-Inertia': 'true',
                'X-Inertia-Version': appVersion,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/html, application/json',
            },
            tags: { kind: 'portal-nav' },
        });
        if (r.status === 409) {
            // A new version was deployed mid-test: reload like the app does.
            const full = http.get(BASE + path, { headers, tags: { kind: 'portal' } });
            appVersion = version(full.body);
            continue;
        }
        check(r, { 'portal page 200': (x) => x.status === 200 && String(x.headers['X-Inertia'] || '') === 'true' });
        think(2, 6);
    }
    if (Math.random() < 0.2) {
        const r = http.get(BASE + pick(PORTAL), { headers, tags: { kind: 'portal' } });
        check(r, { 'full portal page 200': (x) => x.status === 200 });
    }
    if (Math.random() < 0.1) {
        const r = http.post(`${BASE}/app/notifications/read-all`, null, {
            headers: { ...headers, 'X-XSRF-TOKEN': xsrf(jar) },
            redirects: 0,
            tags: { kind: 'write' },
        });
        check(r, { 'marked read': (x) => x.status === 302 || x.status === 200 });
    }
    think(3, 8);
}
