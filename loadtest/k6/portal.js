// Signed-in members: sign in, then move around the portal. Needs a seeded member (php artisan db:seed).
// k6 run -e BASE_URL=... -e EMAIL=jordan@example.com -e PASSWORD='correct horse battery staple' loadtest/k6/portal.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';
export const options = {
    stages: [
        { duration: __ENV.RAMP || '1m', target: Number(__ENV.PEAK_VUS || 300) },
        { duration: __ENV.HOLD || '3m', target: Number(__ENV.PEAK_VUS || 300) },
        { duration: '30s', target: 0 },
    ],
    thresholds: { http_req_failed: ['rate<0.01'], 'http_req_duration{page:portal}': ['p(95)<600'] },
};

function xsrf(jar) {
    const c = jar.cookiesForURL(BASE).find ? null : jar.cookiesForURL(BASE)['XSRF-TOKEN'];
    return c ? decodeURIComponent(c[0]) : '';
}

export default function () {
    const jar = http.cookieJar();
    if (!jar.cookiesForURL(BASE)['t2t_session'] && !__ITER) {
        http.get(`${BASE}/login`);
        const res = http.post(`${BASE}/login`, { email: __ENV.EMAIL, password: __ENV.PASSWORD }, { headers: { 'X-XSRF-TOKEN': xsrf(jar) }, redirects: 0 });
        check(res, { 'signed in': (r) => r.status === 302 });
    }
    for (const path of ['/app', '/app/notifications', '/app/settings/notifications']) {
        const r = http.get(BASE + path, { tags: { page: 'portal' } });
        check(r, { 'portal 200': (x) => x.status === 200 });
        sleep(Math.random() * 3 + 1);
    }
}
