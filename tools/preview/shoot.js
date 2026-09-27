/*
 * Screenshots of every Lens page, for a before/after that is looked at rather
 * than imagined (§4.47, §4.48).
 *
 *     node tools/preview/shoot.js http://127.0.0.1:8088 /tmp/shots [page ...]
 *
 * Three views of each page: a desktop in core's light theme, the same in its
 * dark theme, and a phone. Script errors on a page are printed, because a page
 * that throws still screenshots as something.
 */

const path = require('path');
let chromium;
try {
    ({ chromium } = require('playwright'));
} catch (e) {
    ({ chromium } = require(path.join(process.env.NODE_PATH || '/opt/node22/lib/node_modules', 'playwright')));
}

const PAGES = {
    dashboard: '/ui/lens/dashboard',
    overview: '/ui/lens/overview',
    device: '/ui/lens/device?mac=00:11:32:aa:bb:cc',
    presence: '/ui/lens/presence',
    segments: '/ui/lens/segments',
    wall: '/ui/lens/wall',
    preflight: '/ui/lens/preflight',
    settings: '/ui/lens/settings',
};

const VIEWS = [
    { name: 'desktop', width: 1440, height: 900, theme: 'opnsense' },
    { name: 'dark', width: 1440, height: 900, theme: 'opnsense-dark' },
    { name: 'phone', width: 390, height: 844, theme: 'opnsense', mobile: true },
];

(async () => {
    const [base, out, ...only] = process.argv.slice(2);
    const browser = await chromium.launch();

    for (const [name, url] of Object.entries(PAGES)) {
        if (only.length && !only.includes(name)) {
            continue;
        }
        for (const view of VIEWS) {
            const context = await browser.newContext({
                viewport: { width: view.width, height: view.height },
                deviceScaleFactor: 1,
                isMobile: !!view.mobile,
                hasTouch: !!view.mobile,
            });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', e => errors.push(String(e)));
            const sep = url.includes('?') ? '&' : '?';
            await page.goto(base + url + sep + 'theme=' + view.theme, { waitUntil: 'networkidle' });
            await page.waitForTimeout(name === 'dashboard' ? 6500 : 800);
            const wide = await page.evaluate(() => document.documentElement.scrollWidth);
            await page.screenshot({ path: `${out}/${name}-${view.name}.png`, fullPage: view.name !== 'dark' || name !== 'wall' });
            console.log(`${name}-${view.name}: ${errors.length ? 'ERRORS ' + errors.join(' | ') : 'ok'}`
                + (wide > view.width + 1 ? ` (scrolls sideways: ${wide}px)` : ''));
            await context.close();
        }
    }
    await browser.close();
})();
