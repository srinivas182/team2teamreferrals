// Renders brand/social/share-card.html to the 1200 × 630 link-preview image (run from the repository root).
import { copyFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
await page.goto(`file://${resolve('brand/social/share-card.html')}`);
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: 'brand/social/share-card.png' });
await browser.close();
copyFileSync('brand/social/share-card.png', 'public/brand/share-card.png');
console.log('Wrote brand/social/share-card.png and public/brand/share-card.png');
