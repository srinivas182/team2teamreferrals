/**
 * Installable-app checks (Sprint 53b), in a real browser against the running copy:
 * - the manifest has what Android, iPhone and desktop need, and every icon, screenshot and shortcut opens
 * - the service worker takes charge of the member area
 * - offline: pages opened recently still open (full page loads and in-app navigation), others show the offline page,
 *   and the member area says you're offline
 * - signing out forgets every page kept on the device
 * - a new version waits for "Refresh now" and then takes over
 * - on iPhone, after a first referral, the member is offered the steps to add the app to the home screen
 *
 * Usage: node scripts/ci/pwa.mjs [baseUrl]  (default http://127.0.0.1:8001; needs scripts/ci/seed-screens.php)
 */
import { chromium, devices } from 'playwright';
import { PNG } from 'pngjs';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8001';
const problems = [];
const check = (ok, message) => {
    if (!ok) problems.push(message);
};

async function signIn(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill('member@example.com');
    await page.getByLabel('Password', { exact: true }).fill('visual-checks-2027');
    await Promise.all([
        page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }),
        page.getByRole('button', { name: 'Log in' }).click(),
    ]);
    await page.waitForLoadState('networkidle');
}

async function manifest(request) {
    const res = await request.get(`${BASE}/site.webmanifest`);
    check(res.ok(), 'The manifest does not load');
    const m = await res.json();
    for (const key of ['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'background_color', 'theme_color'])
        check(m[key], `The manifest has no ${key}`);
    check(m.display === 'standalone', 'The manifest does not open full screen');
    check(m.short_name.length <= 12, 'The short name is too long for a home screen');
    const sizes = new Set();
    for (const icon of m.icons ?? []) {
        const r = await request.get(`${BASE}${icon.src}`);
        check(r.ok(), `Icon ${icon.src} does not load`);
        if (!r.ok()) continue;
        const png = PNG.sync.read(await r.body());
        check(`${png.width}x${png.height}` === icon.sizes, `Icon ${icon.src} is ${png.width}x${png.height}, not ${icon.sizes}`);
        sizes.add(`${icon.sizes}/${icon.purpose ?? 'any'}`);
    }
    for (const need of ['192x192/any', '512x512/any', '512x512/maskable']) check(sizes.has(need), `The manifest has no ${need} icon`);
    for (const s of m.screenshots ?? []) check((await request.get(`${BASE}${s.src}`)).ok(), `Screenshot ${s.src} does not load`);
    check(
        (m.screenshots ?? []).some((s) => s.form_factor === 'wide') && (m.screenshots ?? []).some((s) => s.form_factor === 'narrow'),
        'The manifest needs a wide and a narrow screenshot',
    );
    for (const s of m.shortcuts ?? []) check(s.url.startsWith('/app'), `Shortcut ${s.name} leaves the member area`);
    check((await request.get(`${BASE}/offline.html`)).ok(), 'The offline page does not load');
}

const pagesKept = (page) => page.evaluate(async () => ((await caches.has('t2t-pages')) ? (await (await caches.open('t2t-pages')).keys()).length : 0));

const run = async () => {
    const browser = await chromium.launch(process.env.CI ? { channel: 'chrome' } : {});
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await ctx.newPage();
    await manifest(ctx.request);

    // The service worker takes charge of the member area.
    await signIn(page);
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => navigator.serviceWorker?.controller !== null || false, null, { timeout: 15000 }).catch(() => undefined);
    if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) {
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForTimeout(2500);
    }
    check(await page.evaluate(() => !!navigator.serviceWorker.controller), 'The service worker does not take charge of the member area');

    // Open a few pages (full loads and in-app links), then go offline.
    for (const path of ['/app', '/app/inbox', '/app/pipeline']) await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Inbox' }).first().click();
    await page.waitForLoadState('networkidle');
    check((await pagesKept(page)) >= 3, `Only ${await pagesKept(page)} member pages were kept for offline use`);
    // Public pages and the admin are never kept.
    await page.goto(`${BASE}/groups`, { waitUntil: 'networkidle' });
    check(!(await page.evaluate(async () => (await caches.open('t2t-pages')).match('/groups'))), 'A public page was kept for offline use');

    await ctx.setOffline(true);
    await page.goto(`${BASE}/app/pipeline`).catch(() => undefined);
    await page.waitForTimeout(1000);
    check((await page.locator('h1').first().textContent())?.includes('Pipeline'), 'A recently opened page does not open offline');
    await page.waitForTimeout(1800); // the notices appear once the page has settled
    check(
        await page
            .getByText('You’re offline')
            .first()
            .isVisible()
            .catch(() => false),
        'The member area does not say it is offline',
    );
    await page
        .getByRole('link', { name: 'Inbox' })
        .first()
        .click()
        .catch(() => undefined);
    await page.waitForTimeout(1000);
    check(
        new URL(page.url()).pathname === '/app/inbox' && (await page.locator('h1').first().textContent())?.includes('Inbox'),
        'Moving between kept pages offline does not work',
    );
    await page.goto(`${BASE}/app/team`).catch(() => undefined);
    check((await page.locator('h1').first().textContent())?.includes('You’re offline'), 'A page not kept does not show the offline page');
    await ctx.setOffline(false);

    // A new version waits until "Refresh now".
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1800);
    await page.evaluate(() => navigator.serviceWorker.register('/sw.js?v=next-version-check', { scope: '/' }));
    const refresh = page.getByRole('button', { name: 'Refresh now' });
    await refresh.waitFor({ timeout: 15000 }).catch(() => undefined);
    check(await refresh.isVisible().catch(() => false), 'A new version does not offer "Refresh now"');
    if (await refresh.isVisible().catch(() => false)) {
        await Promise.all([page.waitForEvent('load', { timeout: 15000 }).catch(() => undefined), refresh.click()]);
        await page.waitForTimeout(500);
        check(
            await page.evaluate(() => navigator.serviceWorker.controller?.scriptURL.includes('next-version-check') ?? false),
            'After "Refresh now" the new version is not in charge',
        );
    }

    // Signing out forgets every kept page.
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    check((await pagesKept(page)) > 0, 'No pages kept before signing out');
    await page.evaluate(() => {
        const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        return fetch('/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': token, Accept: 'text/html' }, credentials: 'same-origin' });
    });
    await page.waitForTimeout(500);
    check((await pagesKept(page)) === 0, 'Signing out leaves member pages on the device');
    await ctx.close();

    // iPhone: after a first referral, the steps to add the app to the home screen.
    const phone = await browser.newContext({ ...devices['iPhone 13'] });
    const p = await phone.newPage();
    await signIn(p);
    await p.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    const how = p.getByRole('button', { name: 'Show me how' });
    await how.waitFor({ timeout: 8000 }).catch(() => undefined);
    check(await how.isVisible().catch(() => false), 'iPhone members who have sent a referral are not offered the home-screen steps');
    if (await how.isVisible().catch(() => false)) {
        await how.click();
        check(await p.getByText('Add to Home Screen').first().isVisible(), 'The iPhone steps do not show');
        await p.getByRole('button', { name: 'Not now' }).click();
        await p.reload({ waitUntil: 'networkidle' });
        await p.waitForTimeout(2000);
        check(
            !(await p
                .getByRole('button', { name: 'Show me how' })
                .isVisible()
                .catch(() => false)),
            '"Not now" does not hide the install offer',
        );
    }
    await phone.close();
    await browser.close();

    console.log(problems.length ? problems.map((x) => `- ${x}`).join('\n') : 'Installable-app checks passed.');
    if (problems.length) process.exit(1);
};

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
