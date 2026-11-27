// Renders brand/logo/t2t-logo-horizontal.svg to the PNG used at the top of every email (run from the repository root).
// Email apps (Gmail, Outlook) don't show SVG, so emails use this PNG at twice its display size for sharp screens.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const svg = readFileSync(resolve('brand/logo/t2t-logo-horizontal.svg'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 267, height: 64 }, deviceScaleFactor: 2 });
await page.setContent(`<html><body style="margin:0;background:transparent">${svg}</body></html>`);
await page.locator('svg').screenshot({ path: 'public/brand/email-logo.png', omitBackground: true });
await browser.close();
console.log('Wrote public/brand/email-logo.png');
