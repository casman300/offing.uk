// Renders the placeholder icons to PNG with headless Chromium:
//   npx -p playwright node resources/icons/render.mjs
// Outputs to public/icons/ (committed — hosting has no Node). Swap the SVGs
// for real artwork and re-run.
import fs from 'fs';
import path from 'path';
import { chromium } from 'playwright';

const dir = path.dirname(new URL(import.meta.url).pathname);
const out = path.join(dir, '../../public/icons');
const icon = fs.readFileSync(path.join(dir, 'app-icon.svg'), 'utf8');
const badge = fs.readFileSync(path.join(dir, 'badge.svg'), 'utf8');

const jobs = [
    ['icon-192.png', icon, 192, 0],
    ['icon-512.png', icon, 512, 0],
    // Maskable: artwork inside the 80% safe zone, background to the edges.
    ['icon-maskable-512.png', icon, 512, 0.1],
    ['apple-touch-icon.png', icon, 180, 0],
    ['badge-96.png', badge, 96, 0],
];

const browser = await chromium.launch();
const page = await browser.newPage();
for (const [name, svg, size, pad] of jobs) {
    const inner = Math.round(size * (1 - pad * 2));
    const bg = svg === icon ? '#1b5874' : 'transparent';
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(
        `<html><body style="margin:0;background:${bg};display:grid;place-items:center;width:${size}px;height:${size}px">` +
            `<div style="width:${inner}px;height:${inner}px">${svg.replace('<svg ', `<svg width="${inner}" height="${inner}" `)}</div></body></html>`,
    );
    await page.screenshot({ path: path.join(out, name), omitBackground: svg !== icon });
    console.log('wrote', name);
}
await browser.close();
