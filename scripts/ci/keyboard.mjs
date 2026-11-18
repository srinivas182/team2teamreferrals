/**
 * Keyboard checks (Sprint 53a, WCAG 2.1 2.1.1, 2.1.2, 2.4.3, 2.4.7): core journeys done with the keyboard alone.
 * - signing in: Tab through the form and press Enter
 * - the skip link comes first and moves focus to the main content
 * - every focusable element shows a visible focus outline
 * - dialogs (search, Ally) open from the keyboard, keep focus inside, close with Escape and hand focus back
 * - pages reflow at 320 CSS pixels wide (the same as 1280 zoomed to 400%) without scrolling sideways (WCAG 1.4.10)
 *
 * Usage: node scripts/ci/keyboard.mjs [baseUrl]  (default http://127.0.0.1:8001; needs scripts/ci/seed-screens.php)
 */
import { chromium } from 'playwright';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8001';
const problems = [];
const check = (ok, message) => {
    if (!ok) problems.push(message);
};

/** Tab through the page; every element that takes focus must show an outline or a ring. */
async function focusVisible(page, path, max = 40) {
    await page.goto(`${BASE}${path}?tour=off`, { waitUntil: 'networkidle' });
    for (let i = 0; i < max; i++) {
        await page.keyboard.press('Tab');
        const f = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) return null;
            const s = getComputedStyle(el);
            const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
            // A media player's own controls take the focus inside it, so a ring on its wrapper counts too.
            const parent = el.tagName === 'VIDEO' || el.tagName === 'AUDIO' ? el.parentElement : null;
            const ring = s.boxShadow !== 'none' || (parent !== null && getComputedStyle(parent).boxShadow !== 'none');
            return {
                tag: el.tagName.toLowerCase(),
                text: (el.getAttribute('aria-label') ?? el.textContent ?? '').trim().slice(0, 40),
                shown: outline || ring,
            };
        });
        if (f === null) break;
        check(f.shown, `No visible focus on ${path}: <${f.tag}> “${f.text}”`);
    }
}

const run = async () => {
    const browser = await chromium.launch(process.env.CI ? { channel: 'chrome' } : {});
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' })).newPage();

    // The skip link is the first stop and lands on the main content.
    await page.goto(`${BASE}/?tour=off`, { waitUntil: 'networkidle' });
    await page.keyboard.press('Tab');
    check(
        (await page.evaluate(() => document.activeElement?.textContent?.trim())) === 'Skip to content',
        'The first Tab on the home page is not the skip link',
    );
    await page.keyboard.press('Enter');
    check((await page.evaluate(() => location.hash)) === '#main', 'The skip link does not move to the main content');

    for (const path of ['/', '/groups', '/help', '/press', '/login', '/register']) await focusVisible(page, path);

    // Sign in with the keyboard alone.
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email').focus();
    await page.keyboard.type('member@example.com');
    await page.keyboard.press('Tab');
    await page.keyboard.type('visual-checks-2027');
    await Promise.all([
        page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }).catch(() => undefined),
        page.keyboard.press('Enter'),
    ]);
    await page.waitForLoadState('networkidle');
    check(!page.url().includes('/login'), 'Signing in with the keyboard (Tab, then Enter) did not work');

    for (const path of ['/app', '/app/inbox', '/app/send', '/app/pipeline']) await focusVisible(page, path, 30);

    // Search opens with Ctrl+K, keeps focus inside, closes with Escape.
    await page.goto(`${BASE}/app?tour=off`, { waitUntil: 'networkidle' });
    await page.keyboard.press('Control+k');
    await page.waitForTimeout(300);
    const inDialog = async () => page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'));
    check(await inDialog(), 'Ctrl+K does not move focus into the search dialog');
    for (let i = 0; i < 8; i++) await page.keyboard.press('Tab');
    check(await inDialog(), 'Focus escapes the search dialog');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    check((await page.locator('[role="dialog"]').count()) === 0, 'Escape does not close the search dialog');

    // Ally opens from the keyboard and Escape hands focus back to its button.
    const ally = page.locator('[data-tour="ally"]');
    if ((await ally.count()) > 0) {
        await ally.focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(600);
        check(await inDialog(), 'Ally does not move focus into its panel');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        check(
            await page.evaluate(() => document.activeElement?.getAttribute('data-tour') === 'ally'),
            'Closing Ally with Escape does not return focus to its button',
        );
    }

    // Reflow: no sideways scrolling at 320 pixels wide.
    const narrow = await (await browser.newContext({ viewport: { width: 320, height: 640 }, reducedMotion: 'reduce' })).newPage();
    for (const path of [
        '/',
        '/groups',
        '/groups/solar-installers-network',
        '/help',
        '/press',
        '/accessibility',
        '/login',
        '/register',
        '/partners',
    ]) {
        await narrow.goto(`${BASE}${path}?tour=off`, { waitUntil: 'networkidle' });
        const wide = await narrow.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        check(wide <= 1, `${path} scrolls sideways by ${wide}px at 320 pixels wide`);
    }

    await browser.close();
    console.log(problems.length ? problems.map((p) => `- ${p}`).join('\n') : 'Keyboard checks passed.');
    if (problems.length) process.exit(1);
};

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
