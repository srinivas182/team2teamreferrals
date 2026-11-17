/**
 * Press kit files (Sprint 52b): high-resolution screenshots and the captioned one-minute product tour, made from a
 * running copy with sample data: scripts/ci/seed-public-pages.php, then the demo workspace
 * (php artisan tinker --execute="App\\Domains\\Content\\Demo\\DemoWorkspace::create(true);").
 *
 * The tour is filmed as sharp JPEG stills, one encoded file per scene (with a crossfade inside the scene when it
 * scrolls), joined without re-encoding. Captions come from PressController::TOUR, so the page's transcript, the
 * caption track and the burnt-in social version always agree.
 *
 * Usage: node scripts/press/press-kit.mjs [baseUrl]   (default http://127.0.0.1:8001; needs ffmpeg)
 * Writes: public/press-kit/screens/*.jpg, public/press-kit/tour.mp4, tour-social.mp4, tour.vtt and tour-poster.jpg.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8001';
const ROOT = new URL('../..', import.meta.url).pathname;
const OUT = join(ROOT, 'public/press-kit');
const TMP = mkdtempSync(join(tmpdir(), 'press-'));
// Signed in as the demo workspace's team (DemoWorkspace::create), which has referrals at every stage.
const EMAIL = process.env.PRESS_EMAIL ?? 'demo2@demo.invalid';
const PASSWORD = process.env.PRESS_PASSWORD ?? 'demo-password-2026';
const FPS = 30;
const ENCODE = ['-c:v', 'libx264', '-crf', '20', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-r', String(FPS)];

// The captions, from the one place they're defined.
const php = readFileSync(join(ROOT, 'app/Domains/Content/Http/PressController.php'), 'utf8');
const TOUR = [...php.matchAll(/\[(\d+), '([^']+)'\],/g)].map((m) => ({ at: Number(m[1]), text: m[2] }));
const TOTAL = Number(/TOUR_SECONDS = (\d+);/.exec(php)[1]);
if (TOUR.length !== 9) throw new Error(`Expected 9 tour captions, found ${TOUR.length}`);

const STABLE = `*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}
[role=status].bg-orange-500{display:none!important}`;

async function signIn(page) {
    await page.goto(`${BASE}/login`);
    await page.getByLabel('Email').fill(EMAIL);
    await page.getByLabel('Password').fill(PASSWORD);
    await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/login'),
        page.getByRole('button', { name: 'Log in' }).click(),
    ]);
    await page.waitForLoadState('networkidle');
}

async function open(page, path) {
    await page.goto(`${BASE}${path}${path.includes('?') ? '&' : '?'}tour=off`, { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: STABLE });
    await page.evaluate(() => document.fonts.ready);
}

/** A still of the page, scrolled to a height in pixels or to a heading's text. */
async function scroll(page, to) {
    if (typeof to === 'string') {
        await page
            .locator('h2', { hasText: to })
            .first()
            .evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 88));
    } else {
        await page.evaluate((y) => window.scrollTo(0, y), to);
    }
    await page.waitForTimeout(400);
}

async function still(page, file, scrollTo = 0) {
    await scroll(page, scrollTo);
    const path = join(TMP, file);
    await page.screenshot({ path, type: 'jpeg', quality: 92 });
    return path;
}

/** One scene: a held still, or two with a crossfade halfway (for a page that scrolls). */
function scene(name, seconds, a, b = null) {
    const tmp = join(TMP, `${name}.part.mp4`);
    const hold = (i) => `[${i}:v]scale=1280:720,format=yuv420p,loop=-1:1,trim=duration=${seconds},setpts=PTS-STARTPTS[v${i}]`;
    const args = b
        ? [
              '-y',
              '-framerate',
              String(FPS),
              '-i',
              a,
              '-framerate',
              String(FPS),
              '-i',
              b,
              '-filter_complex',
              `${hold(0)};${hold(1)};[v0][v1]xfade=transition=fade:duration=0.8:offset=${(seconds / 2).toFixed(2)},trim=duration=${seconds}[v]`,
              '-map',
              '[v]',
          ]
        : ['-y', '-framerate', String(FPS), '-i', a, '-vf', `scale=1280:720,format=yuv420p,loop=-1:1,trim=duration=${seconds}`];
    execFileSync('ffmpeg', [...args, ...ENCODE, '-loglevel', 'error', tmp]);
    const done = join(TMP, `${name}.mp4`);
    renameSync(tmp, done);
    return done;
}

const vttTime = (s) => `00:${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}.000`;

const run = async () => {
    mkdirSync(join(OUT, 'screens'), { recursive: true });
    const browser = await chromium.launch();

    // Screenshots at twice the pixels, for print.
    const hi = await browser.newContext({
        viewport: { width: 1280, height: 800 },
        deviceScaleFactor: 2,
        colorScheme: 'light',
        reducedMotion: 'reduce',
        locale: 'en-US',
    });
    let page = await hi.newPage();
    const shoot = async (name, path, y = 0) => {
        await open(page, path);
        await scroll(page, y);
        await page.screenshot({ path: join(OUT, 'screens', `${name}.jpg`), type: 'jpeg', quality: 88 });
    };
    await shoot('home', '/');
    await shoot('network-map', '/groups/solar-installers-network', 'Where the network is');
    await signIn(page);
    await shoot('member-home', '/app');
    await shoot('send-referral', '/app/send');
    await shoot('pipeline', '/app/pipeline');
    await hi.close();
    const phone = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
        reducedMotion: 'reduce',
        locale: 'en-US',
    });
    page = await phone.newPage();
    await signIn(page);
    await open(page, '/app');
    await page.screenshot({ path: join(OUT, 'screens', 'phone.jpg'), type: 'jpeg', quality: 88 });
    await phone.close();

    // The tour: 1280×720 stills (signed out for the public scenes, signed in for the rest).
    const seconds = TOUR.map((c, i) => (TOUR[i + 1]?.at ?? TOTAL) - c.at);
    const video = { viewport: { width: 1280, height: 720 }, colorScheme: 'light', reducedMotion: 'reduce', locale: 'en-US' };
    const out = await browser.newContext(video);
    page = await out.newPage();
    const scenes = [];
    await open(page, '/');
    scenes.push(scene('s0', seconds[0], await still(page, 'home-a.jpg'), await still(page, 'home-b.jpg', 520)));
    await open(page, '/groups');
    scenes.push(scene('s1', seconds[1], await still(page, 'groups.jpg')));
    await open(page, '/groups/solar-installers-network');
    scenes.push(scene('s2', seconds[2], await still(page, 'group-a.jpg'), await still(page, 'group-b.jpg', 'Where the network is')));
    await out.close();
    const inside = await browser.newContext(video);
    page = await inside.newPage();
    await signIn(page);
    for (const [i, path, scroll] of [
        [3, '/app', 0],
        [4, '/app/send', 320],
        [5, '/app/inbox', 0],
        [6, '/app/pipeline', 0],
        [7, '/app/earnings', 0],
    ]) {
        await open(page, path);
        const a = await still(page, `m${i}-a.jpg`);
        scenes.push(scene(`s${i}`, seconds[i], a, scroll ? await still(page, `m${i}-b.jpg`, scroll) : null));
    }
    // The end card.
    const logo = readFileSync(join(ROOT, 'brand/logo/t2t-logo-stacked-on-dark.svg'), 'utf8');
    await page.setContent(
        `<body style="margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#0B1736;color:#fff;font-family:Inter,sans-serif">
        <div style="width:420px">${logo}</div><p style="font-size:34px;font-weight:700;margin:36px 0 8px">Claim your city</p>
        <p style="font-size:24px;color:#C9D2EA;margin:0">${new URL(BASE).host === '127.0.0.1:8001' ? 'teamtoteamreferrals.com' : new URL(BASE).host}</p></body>`,
    );
    scenes.push(scene('s8', seconds[8], await still(page, 'end.jpg')));
    await browser.close();

    // Join the scenes, then the captions: a track for the page, and a copy with them burnt in for social media.
    writeFileSync(join(TMP, 'list.txt'), scenes.map((s) => `file '${s}'`).join('\n'));
    const vtt = ['WEBVTT', '', ...TOUR.flatMap((c, i) => [`${i + 1}`, `${vttTime(c.at)} --> ${vttTime(TOUR[i + 1]?.at ?? TOTAL)}`, c.text, ''])].join(
        '\n',
    );
    writeFileSync(join(OUT, 'tour.vtt'), vtt);
    const final = join(TMP, 'tour.part.mp4');
    execFileSync('ffmpeg', [
        '-y',
        '-loglevel',
        'error',
        '-f',
        'concat',
        '-safe',
        '0',
        '-i',
        join(TMP, 'list.txt'),
        '-c',
        'copy',
        '-movflags',
        '+faststart',
        final,
    ]);
    renameSync(final, join(OUT, 'tour.mp4'));
    writeFileSync(join(TMP, 'tour.vtt'), vtt);
    const social = join(TMP, 'social.part.mp4');
    execFileSync('ffmpeg', [
        '-y',
        '-loglevel',
        'error',
        '-i',
        join(OUT, 'tour.mp4'),
        '-vf',
        `subtitles=${join(TMP, 'tour.vtt')}:force_style='FontName=Inter,FontSize=15,Bold=1,PrimaryColour=&H00FFFFFF,BackColour=&H2036170B,BorderStyle=4,Outline=0,Shadow=0,MarginV=36'`,
        ...ENCODE,
        '-movflags',
        '+faststart',
        social,
    ]);
    renameSync(social, join(OUT, 'tour-social.mp4'));
    execFileSync('ffmpeg', [
        '-y',
        '-loglevel',
        'error',
        '-ss',
        '1',
        '-i',
        join(OUT, 'tour.mp4'),
        '-frames:v',
        '1',
        '-q:v',
        '3',
        join(OUT, 'tour-poster.jpg'),
    ]);
    console.log(`Press kit written to public/press-kit (${scenes.length} scenes, ${TOTAL}s).`);
};

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
