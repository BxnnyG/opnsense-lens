/*
 * Screenshots of every Lens page, for a before/after that is looked at rather
 * than imagined (§4.47, §4.48) -- in the preview, or on a real box.
 *
 *     node tools/preview/shoot.js http://127.0.0.1:8088 /tmp/shots [page ...]
 *
 * Three views of each page in the preview: a desktop in core's light theme, the
 * same in its dark theme, and a phone. On a real box (tools/round/round.py) it
 * logs in first and takes the desktop and the phone; the theme there is the
 * user's own.
 *
 * Environment, all optional:
 *   LENS_LOGIN=1 with LENS_UI_USER / LENS_UI_PASS   log in to a real box first
 *   LENS_VIEWS=desktop,phone                        which views to take
 *   LENS_INSECURE=1                                 accept a self-signed certificate
 *   LENS_RESULTS=/path/pages.json                   write what was found, per page
 *
 * Script errors are reported, because a page that throws still screenshots as
 * something; so is a page wider than its screen.
 */

const fs = require('fs');
const path = require('path');
let chromium;
try {
    ({ chromium } = require('playwright'));
} catch (e) {
    ({ chromium } = require(path.join(process.env.NODE_PATH || '/opt/node22/lib/node_modules', 'playwright')));
}

const PAGES = {
    dashboard: '/ui/lens/dashboard',
    events: '/ui/lens/events?days=30',
    overview: '/ui/lens/overview',
    device: '/ui/lens/device?mac=00:11:32:aa:bb:cc',
    presence: '/ui/lens/presence',
    segments: '/ui/lens/segments',
    dns: '/ui/lens/dns',
    report: '/ui/lens/report',
    wall: '/ui/lens/wall',
    preflight: '/ui/lens/preflight',
    settings: '/ui/lens/settings',
    privacy: '/ui/lens/privacy?mac=2c:aa:8e:40:50:60',
};

const ALL_VIEWS = [
    { name: 'desktop', width: 1440, height: 900, theme: 'opnsense' },
    { name: 'dark', width: 1440, height: 900, theme: 'opnsense-dark' },
    { name: 'phone', width: 390, height: 844, theme: 'opnsense', mobile: true },
];

(async () => {
    const [base, out, ...only] = process.argv.slice(2);
    const login = process.env.LENS_LOGIN === '1';
    const wanted = (process.env.LENS_VIEWS || (login ? 'desktop,phone' : 'desktop,dark,phone')).split(',');
    const views = ALL_VIEWS.filter(v => wanted.includes(v.name));
    const insecure = process.env.LENS_INSECURE === '1';
    const results = [];

    const browser = await chromium.launch();
    let storageState;

    if (login) {
        const context = await browser.newContext({ ignoreHTTPSErrors: insecure });
        const page = await context.newPage();
        await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
        await page.fill('#usernamefld', process.env.LENS_UI_USER || '');
        await page.fill('#passwordfld', process.env.LENS_UI_PASS || '');
        await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=login]')]);
        if (await page.$('#usernamefld')) {
            console.error('login failed: the login form is still there');
            process.exit(1);
        }
        /* a real device for the device page: the busiest one the box knows */
        const mac = await page.evaluate(async () => {
            try {
                const reply = await fetch('/api/lens/devices/list?hours=24');
                const report = await reply.json();
                return (report.devices || [])[0] ? report.devices[0].mac : null;
            } catch (e) {
                return null;
            }
        });
        if (mac) {
            PAGES.device = '/ui/lens/device?mac=' + encodeURIComponent(mac);
        }
        storageState = await context.storageState();
        await context.close();
    }

    for (const [name, url] of Object.entries(PAGES)) {
        if (only.length && !only.includes(name)) {
            continue;
        }
        for (const view of views) {
            const context = await browser.newContext({
                viewport: { width: view.width, height: view.height },
                deviceScaleFactor: 1,
                isMobile: !!view.mobile,
                hasTouch: !!view.mobile,
                ignoreHTTPSErrors: insecure,
                storageState: storageState,
            });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', e => errors.push(String(e)));
            const sep = url.includes('?') ? '&' : '?';
            const target = base + url + (login ? '' : sep + 'theme=' + view.theme);
            const started = Date.now();
            await page.goto(target, { waitUntil: 'networkidle' });
            const ms = Date.now() - started;
            await page.waitForTimeout(name === 'dashboard' ? 6500 : 800);
            const wide = await page.evaluate(() => document.documentElement.scrollWidth);
            const file = `${name}-${view.name}.png`;
            await page.screenshot({ path: `${out}/${file}`, fullPage: true });
            const sideways = wide > view.width + 1 ? wide : 0;
            results.push({ page: name, view: view.name, file: file, ms: ms, errors: errors, sideways: sideways });
            console.log(`${name}-${view.name}: ${errors.length ? 'ERRORS ' + errors.join(' | ') : 'ok'}`
                + (sideways ? ` (scrolls sideways: ${wide}px)` : '') + ` ${ms} ms`);
            await context.close();
        }
    }
    await browser.close();

    if (process.env.LENS_RESULTS) {
        fs.writeFileSync(process.env.LENS_RESULTS, JSON.stringify(results, null, 1));
    }
})();
