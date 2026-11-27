/**
 * Visual checks of key screens (Sprint 52a): public pages, the member portal, the admin and the partner account,
 * at desktop width in light mode, plus a few in dark mode and on a phone. Each screenshot is compared with the
 * approved one in tests/visual/baseline; differences above a small threshold fail the check (add the PR label
 * "visual-ok" when a change is intended, then commit the new images from the report). It also checks that every
 * guided-tour step points at something that's on the page, and runs an accessibility check (axe-core, WCAG 2.1 A and AA)
 * on every screen: any serious or critical problem fails the check (Sprint 53a).
 *
 * Usage: node scripts/ci/visual.mjs [baseUrl]  (default http://127.0.0.1:8001)
 * Needs scripts/ci/seed-public-pages.php and scripts/ci/seed-screens.php.
 */
import { execSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import pixelmatch from 'pixelmatch';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8001';
const ROOT = new URL('../..', import.meta.url).pathname;
const BASELINE = join(ROOT, 'tests/visual/baseline');
const OUT = join(ROOT, 'visual-report');
const MAX_DIFF = 0.005; // share of pixels allowed to differ (anti-aliasing, a changing date)
const PASSWORD = 'visual-checks-2027';

const PUBLIC = [
    ['home', '/'],
    ['groups', '/groups'],
    ['group', '/groups/solar-installers-network'],
    ['industry-hub', '/industries/solar'],
    ['city', '/industries/solar/tx/austin'],
    ['profile', '/t/lone-star-solar'],
    ['find-a-pro', '/find-a-pro'],
    ['blog', '/blog'],
    ['help', '/help'],
    ['help-article', '/help/how-to-claim-a-seat'],
    ['help-search', '/help/search?q=claim+seat'],
    ['contact-support', '/support/new'],
    ['partners', '/partners'],
    ['partners-advertise', '/partners/advertise'],
    ['partner', '/partner/stage-right-austin'],
    ['partners-city', '/partners/home-stagers/tx/austin'],
    ['partner-sign-in', '/partner-portal'],
    ['login', '/login'],
    ['register', '/register'],
];
const MEMBER = [
    ['app-home', '/app'],
    ['app-inbox', '/app/inbox'],
    ['app-pipeline', '/app/pipeline'],
    ['app-sent', '/app/sent'],
    ['app-earnings', '/app/earnings'],
    ['app-groups', '/app/groups'],
    ['app-groups-mine', '/app/groups/mine'],
    ['app-team', '/app/team'],
    ['app-team-performance', '/app/team/performance'],
    ['app-billing', '/app/billing'],
    ['app-dashboard', '/app/dashboard'],
    ['app-notifications', '/app/notifications'],
    ['app-support', '/app/support'],
    ['app-partners', '/app/partners'],
];
const ADMIN = [
    ['admin-home', '/admin'],
    ['admin-groups', '/admin/groups'],
    ['admin-billing', '/admin/billing'],
    ['admin-revenue', '/admin/revenue'],
    ['admin-partners', '/admin/partners'],
    ['admin-support', '/admin/support'],
    ['admin-help', '/admin/help'],
    ['admin-ally', '/admin/ally'],
    ['admin-growth', '/admin/growth/prospects'],
    ['admin-system', '/admin/system'],
];
// Also in dark mode and on a phone.
const EXTRA = ['home', 'help', 'partner', 'partner-account', 'app-home', 'app-pipeline', 'admin-home'];

const STABLE_CSS = `*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}
time,[data-vr-mask],[data-vr-live] .tabular{visibility:hidden!important}
[data-pwa-banners]{display:none!important}`;

/** RFC 6238 time-based code from a base32 secret (for the admin's two-step sign-in). */
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const h = createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

let lastCode = '';

async function signIn(page, email, secret) {
    await page.goto(`${BASE}/login`);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    // Forms post in the background (Inertia): wait for the answer, then for the page to settle.
    const posted = (path) => page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === path);
    await Promise.all([posted('/login'), page.getByRole('button', { name: 'Log in' }).click()]);
    await page.waitForLoadState('networkidle');
    // A code can only be used once, so a second sign-in in the same 30 seconds waits for the next code.
    for (let attempt = 0; secret && page.url().includes('two-factor') && attempt < 2; attempt++) {
        if (attempt > 0 || totp(secret) === lastCode) await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
        lastCode = totp(secret);
        await page.getByLabel('Authentication code').fill(lastCode);
        await Promise.all([posted('/two-factor-challenge'), page.getByRole('button', { name: 'Continue' }).click()]);
        // The next page loads after the answer; a refused code leaves the challenge on screen.
        await page.waitForURL((u) => !u.pathname.includes('two-factor'), { timeout: 10_000 }).catch(() => undefined);
        await page.waitForLoadState('networkidle');
    }
    if (/\/(login|two-factor)/.test(new URL(page.url()).pathname)) throw new Error(`Could not sign in as ${email}`);
}

const AXE = join(ROOT, 'node_modules/axe-core/axe.min.js');

/** WCAG 2.1 A and AA problems on the page that are serious or critical. */
async function accessibility(page, name, problems) {
    await page.addScriptTag({ path: AXE });
    const found = await page.evaluate(async () => {
        const r = await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] });
        return r.violations
            .filter((v) => v.impact === 'serious' || v.impact === 'critical')
            .map((v) => `${v.id} (${v.impact}, ${v.nodes.length}×): ${v.help} — ${v.nodes[0].target.join(' ')}`);
    });
    for (const f of found) problems.push(`Accessibility on ${name}: ${f}`);
}

async function shoot(page, name, path, results, problems) {
    const res = await page.goto(`${BASE}${path}${path.includes('?') ? '&' : '?'}tour=off`, { waitUntil: 'networkidle' });
    if (!res || res.status() >= 400) {
        results.push({ name, status: 'error', detail: `HTTP ${res?.status() ?? 'no response'} for ${path}` });
        return;
    }
    await page.addStyleTag({ content: STABLE_CSS });
    await page.evaluate(() => document.fonts.ready);
    const shot = await page.screenshot({ fullPage: false });
    await accessibility(page, name, problems);
    writeFileSync(join(OUT, 'current', `${name}.png`), shot);
    const base = join(BASELINE, `${name}.png`);
    if (!existsSync(base)) {
        writeFileSync(join(OUT, 'new', `${name}.png`), shot);
        results.push({ name, status: 'new' });
        return;
    }
    const a = PNG.sync.read(readFileSync(base));
    const b = PNG.sync.read(shot);
    if (a.width !== b.width || a.height !== b.height) {
        results.push({ name, status: 'changed', detail: `size ${a.width}×${a.height} → ${b.width}×${b.height}` });
        writeFileSync(join(OUT, 'new', `${name}.png`), shot);
        return;
    }
    const diff = new PNG({ width: a.width, height: a.height });
    const n = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.15 });
    const share = n / (a.width * a.height);
    if (share > MAX_DIFF) {
        writeFileSync(join(OUT, 'diff', `${name}.png`), PNG.sync.write(diff));
        writeFileSync(join(OUT, 'new', `${name}.png`), shot);
        results.push({ name, status: 'changed', detail: `${(share * 100).toFixed(2)}% of pixels` });
    } else {
        results.push({ name, status: 'same' });
    }
}

/** Every tour step must point at something visible on its page. */
async function checkTours(page, problems) {
    const src = readFileSync(join(ROOT, 'resources/js/lib/tours.ts'), 'utf8');
    const tours = [...src.matchAll(/id: '([a-z-]+)',\s*path: \/\^(.+?)\$?\/,\s*steps: \[([\s\S]*?)\n {8}\],/g)].map((m) => ({
        id: m[1],
        path: m[2].replace(/\\\//g, '/').replace(/\/\?$/, '').replace(/\$$/, '') || '/',
        targets: [...m[3].matchAll(/target: '([a-z-]+)'/g)].map((t) => t[1]),
    }));
    for (const t of tours) {
        await page.goto(`${BASE}${t.path}?tour=off`, { waitUntil: 'networkidle' });
        for (const target of t.targets) {
            const visible = await page
                .locator(`[data-tour="${target}"]`)
                .first()
                .isVisible()
                .catch(() => false);
            if (!visible) problems.push(`Tour ${t.id}: nothing visible for step "${target}" on ${t.path}`);
        }
    }
    if (tours.length === 0) problems.push('No tours found in resources/js/lib/tours.ts');
}

const run = async () => {
    for (const d of ['current', 'new', 'diff']) mkdirSync(join(OUT, d), { recursive: true });
    const browser = await chromium.launch(process.env.CI ? { channel: 'chrome' } : {});
    const results = [];
    const problems = [];
    const variants = [
        ['', { viewport: { width: 1280, height: 800 }, colorScheme: 'light' }],
        ['dark-', { viewport: { width: 1280, height: 800 }, colorScheme: 'dark' }],
        ['phone-', { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true, colorScheme: 'light' }],
    ];
    for (const [prefix, options] of variants) {
        const only = (list) => (prefix === '' ? list : list.filter(([n]) => EXTRA.includes(n)));
        // bypassCSP only lets the check inject axe-core; the pages are otherwise exactly as served.
        const ctx = await browser.newContext({
            ...options,
            reducedMotion: 'reduce',
            locale: 'en-US',
            timezoneId: 'America/Chicago',
            bypassCSP: true,
        });
        const page = await ctx.newPage();
        for (const [n, p] of only(PUBLIC)) await shoot(page, prefix + n, p, results, problems);
        await signIn(page, 'member@example.com');
        for (const [n, p] of only(MEMBER)) await shoot(page, prefix + n, p, results, problems);
        await ctx.clearCookies();
        // The partner account: signed in with the emailed link, made here the same way.
        const link = execSync(
            `php artisan tinker --execute="echo URL::temporarySignedRoute('partners.portal.enter', now()->addHour(), ['t' => encrypt('partner@example.com')]);"`,
            { cwd: ROOT },
        )
            .toString()
            .trim();
        await page.goto(link, { waitUntil: 'networkidle' });
        const account = new URL(page.url()).pathname;
        if (prefix === '' || EXTRA.includes('partner-account')) await shoot(page, prefix + 'partner-account', account, results, problems);
        await ctx.clearCookies();
        await signIn(page, 'admin@example.com', 'JBSWY3DPEHPK3PXP');
        for (const [n, p] of only(ADMIN)) await shoot(page, prefix + n, p, results, problems);
        if (prefix === '') await checkTours(page, problems);
        await ctx.close();
    }
    await browser.close();

    const changed = results.filter((r) => r.status === 'changed');
    const errors = results.filter((r) => r.status === 'error');
    const fresh = results.filter((r) => r.status === 'new');
    const lines = [
        `# Visual checks`,
        '',
        `${results.length} screens: ${results.filter((r) => r.status === 'same').length} unchanged, ${changed.length} changed, ${fresh.length} new, ${errors.length} failed to load.`,
        '',
        ...changed.map((r) => `- changed: ${r.name} (${r.detail}) — see diff/${r.name}.png and new/${r.name}.png`),
        ...fresh.map((r) => `- new (no approved image yet): ${r.name}`),
        ...errors.map((r) => `- failed: ${r.name}: ${r.detail}`),
        ...problems.map((p) => `- ${p}`),
        '',
        'To approve changes: add the PR label "visual-ok", then copy the images from new/ into tests/visual/baseline/.',
    ];
    writeFileSync(join(OUT, 'report.md'), lines.join('\n'));
    console.log(lines.join('\n'));
    const approved = process.env.VISUAL_OK === 'true';
    if (errors.length || problems.length || (changed.length && !approved)) process.exit(1);
};

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
