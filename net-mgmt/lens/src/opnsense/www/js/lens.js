/*
 * Lens -- the little every page shares (DESIGN §4.59). Layout only: what a
 * number means is decided in PHP or Python, never here (§4.21).
 *
 *   Lens.theme()        which of core's themes is showing
 *   Lens.tip(el, fn)    a tooltip whose lines fn() returns, on hover and focus
 *   Lens.when(at, step) a time label in the reader's own locale
 *   Lens.axis(...)      time labels under an SVG chart
 *   Lens.filter         the networks and tags every filtering page follows (§4.65)
 *   Lens.palette        Ctrl-K: find a device, a network or a Lens page (§4.65)
 */
(function () {
    'use strict';

    /*
     * Core switches themes by swapping stylesheets (opnsense-auto rewrites the
     * <link> hrefs and fires a resize), not by setting a class. So the theme is
     * read from the surface Lens is drawn on.
     */
    const theme = () => {
        const surface = document.querySelector('.page-content-main') || document.body;
        const rgb = (getComputedStyle(surface).backgroundColor.match(/\d+(\.\d+)?/g) || [255, 255, 255])
            .slice(0, 3).map(Number);
        const luminance = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
        const dark = luminance < 0.45;
        document.documentElement.classList.toggle('lens-dark', dark);
        return dark ? 'dark' : 'light';
    };

    theme();
    document.addEventListener('DOMContentLoaded', theme);
    window.addEventListener('load', theme);
    window.addEventListener('resize', theme);

    /* ------------------------------------------------------------- tooltip */

    let box = null;

    const place = (event) => {
        const pad = 14;
        const width = box.offsetWidth;
        const height = box.offsetHeight;
        let left = event.clientX + pad;
        let top = event.clientY + pad;
        if (left + width > window.innerWidth - 8) {
            left = event.clientX - width - pad;
        }
        if (top + height > window.innerHeight - 8) {
            top = event.clientY - height - pad;
        }
        box.style.left = Math.max(8, left) + 'px';
        box.style.top = Math.max(8, top) + 'px';
    };

    /*
     * lines: [{ value, label, key }] -- the value leads, the label follows, a
     * short stroke of the series colour keys it. Written with textContent: a
     * device name is whatever the device announced (PROCESS edge case 6).
     */
    const show = (lines, event) => {
        if (!box) {
            box = document.createElement('div');
            box.className = 'lens-tip';
            document.body.appendChild(box);
        }
        box.textContent = '';
        for (const line of lines) {
            const row = document.createElement('div');
            if (line.key) {
                const key = document.createElement('span');
                key.className = 'lens-tip-key';
                key.style.background = line.key;
                row.appendChild(key);
            }
            if (line.value !== undefined && line.value !== null && line.value !== '') {
                const value = document.createElement('b');
                value.textContent = line.value;
                row.appendChild(value);
                row.appendChild(document.createTextNode(line.label ? ' ' : ''));
            }
            if (line.label) {
                const label = document.createElement('span');
                label.className = line.value ? 'lens-tip-muted' : '';
                label.textContent = line.label;
                row.appendChild(label);
            }
            box.appendChild(row);
        }
        box.style.display = 'block';
        if (event && event.clientX !== undefined) {
            place(event);
        } else if (event && event.target && event.target.getBoundingClientRect) {
            const r = event.target.getBoundingClientRect();
            place({ clientX: r.left + r.width / 2, clientY: r.top });
        }
    };

    const hide = () => {
        if (box) {
            box.style.display = 'none';
        }
    };

    const tip = (el, lines) => {
        const on = (event) => {
            const content = typeof lines === 'function' ? lines(event) : lines;
            if (content && content.length) {
                show(content, event);
            } else {
                hide();
            }
        };
        el.addEventListener('pointermove', on);
        el.addEventListener('focus', on);
        el.addEventListener('pointerleave', hide);
        el.addEventListener('blur', hide);
        return el;
    };

    /* ---------------------------------------------------------- time labels */

    /*
     * How times and dates are written, as set under Services: Lens: Settings
     * (operator, 2026-10-09). Kept for the session so no page waits for it; the
     * first page of a session asks and writes in the browser's own way until
     * the answer is there. Saving the settings forgets it.
     */
    const display = (() => {
        const KEY = 'lens.display';
        let prefs = { clock: 'auto', date_order: 'auto' };
        let kept = false;
        try {
            const stored = JSON.parse(sessionStorage.getItem(KEY) || 'null');
            if (stored) {
                prefs = stored;
                kept = true;
            }
        } catch (e) {
            /* no storage: the browser's own way */
        }
        if (!kept) {
            fetch('/api/lens/dashboard/display', { credentials: 'same-origin' })
                .then(reply => reply.ok ? reply.json() : null)
                .then((answer) => {
                    if (answer && answer.clock) {
                        prefs = { clock: answer.clock, date_order: answer.date_order };
                        try {
                            sessionStorage.setItem(KEY, JSON.stringify(prefs));
                        } catch (e) {
                            /* this page only */
                        }
                    }
                })
                .catch(() => {});
        }
        return {
            get: () => prefs,
            forget: () => {
                try {
                    sessionStorage.removeItem(KEY);
                } catch (e) {
                    /* nothing kept */
                }
            },
        };
    })();

    const formats = {};
    const format = (options) => {
        const clock = display.get().clock;
        const withClock = options.hour && clock !== 'auto'
            ? Object.assign({}, options, { hourCycle: clock === '12' ? 'h12' : 'h23' }) : options;
        const key = clock + JSON.stringify(withClock);
        if (!formats[key]) {
            formats[key] = new Intl.DateTimeFormat(undefined, withClock);
        }
        return formats[key];
    };

    /* the clock time of a moment, as set */
    const time = at => format({ hour: '2-digit', minute: '2-digit' }).format(new Date(at * 1000));

    /* a date in numbers, in the order set; "auto" is the browser's own */
    const date = (at) => {
        const d = new Date(at * 1000);
        const pad = n => String(n).padStart(2, '0');
        switch (display.get().date_order) {
            case 'dmy':
                return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
            case 'mdy':
                return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear();
            case 'ymd':
                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            default:
                return format({ year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
        }
    };

    /* both, for a moment that needs its day */
    const stamp = at => date(at) + ' ' + time(at);

    /*
     * A slice of `step` seconds starting at `at`, labelled the way the reader's
     * own browser would: 14:00 or 2 PM for an hour, "Tue 23" for a day.
     */
    const when = (at, step, long) => {
        const date = new Date(at * 1000);
        if (step >= 86400) {
            return format(long ? { weekday: 'short', day: 'numeric', month: 'short' }
                               : { weekday: 'short', day: 'numeric' }).format(date);
        }
        return format(long ? { weekday: 'short', hour: '2-digit', minute: '2-digit' }
                           : { hour: '2-digit', minute: '2-digit' }).format(date);
    };

    /*
     * Time labels in an HTML row under a chart, not inside the SVG: the charts
     * stretch their viewBox to the card (preserveAspectRatio="none"), and text
     * inside a stretched SVG is stretched with it. About one label per `every`
     * pixels of the row's real width, so a phone gets fewer than a desktop.
     *
     * fraction(i) is where point i sits across the chart, 0 to 1.
     */
    const axis = (row, points, fraction, step, every) => {
        row.textContent = '';
        if (!points.length) {
            return;
        }
        const width = row.clientWidth || 600;
        const count = Math.max(1, Math.min(points.length - 1, Math.floor(width / (every || 120))));
        const stride = Math.max(1, Math.ceil((points.length - 1) / count));
        for (let i = 0; i < points.length; i += stride) {
            const tick = document.createElement('span');
            /* a label at the right edge ends there instead of hanging over it */
            tick.className = 'lens-tick' + (i > 0 && fraction(i) > 0.94 ? ' last' : '');
            tick.style.left = (fraction(i) * 100) + '%';
            tick.textContent = when(points[i].at, step);
            row.appendChild(tick);
        }
    };

    /* ------------------------------------------------------ filter (§4.65) */

    /*
     * Networks and tags, like Palo Alto's global filter: in the address, so a
     * filtered page can be sent to someone, and carried in the tab, so the next
     * Lens page opens filtered -- visibly, with one click to clear. Only pages
     * that apply it mount the bar (§4.65).
     */
    const KINDS = { segment: 'Network', tag: 'Tag' };
    const FILTER_KEY = 'lens.filter';
    const filter = (() => {
        const state = { segment: new Map(), tag: new Map() };
        const listeners = [];
        let bar = null;

        const stored = () => {
            try {
                return JSON.parse(sessionStorage.getItem(FILTER_KEY) || '{}') || {};
            } catch (e) {
                return {};
            }
        };

        const load = () => {
            const query = new URLSearchParams(location.search);
            const fromUrl = Object.keys(KINDS).some(kind => query.getAll(kind).length);
            const saved = stored();
            for (const kind of Object.keys(KINDS)) {
                const labels = new Map(saved[kind] || []);
                const values = fromUrl ? query.getAll(kind) : [...labels.keys()];
                state[kind] = new Map(values.filter(Boolean).map(value => [value, labels.get(value) || value]));
            }
        };

        const save = () => {
            try {
                sessionStorage.setItem(FILTER_KEY, JSON.stringify({
                    segment: [...state.segment.entries()], tag: [...state.tag.entries()]
                }));
            } catch (e) {
                /* a tab without storage still filters this page */
            }
            const url = new URL(location.href);
            for (const kind of Object.keys(KINDS)) {
                url.searchParams.delete(kind);
                for (const value of state[kind].keys()) {
                    url.searchParams.append(kind, value);
                }
            }
            history.replaceState(null, '', url);
        };

        const active = () => Object.keys(KINDS).some(kind => state[kind].size > 0);

        const draw = () => {
            if (!bar) {
                return;
            }
            bar.textContent = '';
            bar.style.display = active() ? '' : 'none';
            const label = document.createElement('span');
            label.className = 'lens-filterbar-label';
            label.textContent = 'Filtered to';
            bar.appendChild(label);
            for (const kind of Object.keys(KINDS)) {
                for (const [value, name] of state[kind]) {
                    const pill = document.createElement('span');
                    pill.className = 'lens-pill';
                    pill.textContent = KINDS[kind] + ': ' + name;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.setAttribute('aria-label', 'remove');
                    remove.textContent = '\u00d7';
                    remove.addEventListener('click', () => toggle(kind, value));
                    pill.appendChild(remove);
                    bar.appendChild(pill);
                }
            }
            const clear = document.createElement('a');
            clear.href = '#';
            clear.className = 'lens-filterbar-clear';
            clear.textContent = 'clear';
            clear.addEventListener('click', (event) => {
                event.preventDefault();
                reset();
            });
            bar.appendChild(clear);
        };

        const changed = () => {
            save();
            draw();
            listeners.forEach(fn => fn());
        };

        const toggle = (kind, value, name) => {
            if (state[kind].has(value)) {
                state[kind].delete(value);
            } else {
                state[kind].set(value, name || value);
            }
            changed();
        };

        const reset = () => {
            Object.keys(KINDS).forEach(kind => state[kind].clear());
            changed();
        };

        /*
         * item: { interfaces: [...], tags: [...] }, or null for something about
         * every network -- the line, the internet -- which no filter hides.
         */
        const matches = (item) => {
            if (!item || !active()) {
                return true;
            }
            if (state.segment.size && !(item.interfaces || []).some(i => state.segment.has(i))) {
                return false;
            }
            if (state.tag.size && !(item.tags || []).some(t => state.tag.has(t))) {
                return false;
            }
            return true;
        };

        /* where: the element the bar goes above; onChange: redraw the page */
        const mount = (where, onChange) => {
            bar = document.createElement('div');
            bar.className = 'lens-filterbar';
            where.parentNode.insertBefore(bar, where);
            if (onChange) {
                listeners.push(onChange);
            }
            draw();
        };

        load();
        return {
            mount: mount, matches: matches, toggle: toggle, reset: reset, active: active,
            has: (kind, value) => state[kind].has(value),
            values: (kind) => new Set(state[kind].keys()),
            /* a page that learns a network's real name tells the bar */
            name: (kind, value, name) => {
                if (state[kind].has(value) && state[kind].get(value) !== name) {
                    state[kind].set(value, name);
                    save();
                    draw();
                }
            },
        };
    })();

    /* ----------------------------------------------------- palette (§4.65) */

    const INDEX_KEY = 'lens.index.2';     /* .2: carries core's menu since 0.33 */
    const INDEX_FOR = 5 * 60 * 1000;
    /*
     * Core's own menu, as its search box at the top reads it (layouts/default.volt):
     * the same request, so Ctrl-K finds every page of OPNsense the user may open,
     * not only Lens's (operator, 2026-10-07: one search, not two).
     */
    const menu = () => fetch('/api/core/menu/search/', { credentials: 'same-origin' })
        .then(reply => reply.ok ? reply.json() : [])
        .then(items => (Array.isArray(items) ? items : []).filter(item => item.Url).map((item) => {
            const text = (html) => new DOMParser().parseFromString(String(html || ''), 'text/html')
                .documentElement.textContent;
            const name = text(item.breadcrumb);
            const url = text(item.Url);
            return { name: name, sub: '', url: url.charAt(0) === '/' ? url : '/' + url, icon: 'fa-bars',
                     haystack: name.toLowerCase() };
        }))
        .catch(() => []);

    const palette = (() => {
        let index = null;
        let root = null;
        let input = null;
        let list = null;
        let shown = [];
        let cursor = 0;

        const fetchIndex = () => {
            try {
                const cached = JSON.parse(sessionStorage.getItem(INDEX_KEY) || 'null');
                if (cached && Date.now() - cached.at < INDEX_FOR) {
                    index = cached.data;
                    return Promise.resolve();
                }
            } catch (e) {
                /* no storage: ask every time */
            }
            return Promise.all([
                fetch('/api/lens/devices/index', { credentials: 'same-origin' })
                    .then(reply => reply.ok ? reply.json() : Promise.reject(reply.status)),
                menu()
            ])
                .then(([data, items]) => {
                    data.menu = items;
                    index = data;
                    try {
                        sessionStorage.setItem(INDEX_KEY, JSON.stringify({ at: Date.now(), data: data }));
                    } catch (e) {
                        /* kept for this page only */
                    }
                })
                .catch(() => {
                    index = { pages: [], networks: [], devices: [], failed: true };
                });
        };

        const find = (query) => {
            const words = query.toLowerCase().split(/\s+/).filter(Boolean);
            const rank = (item) => {
                const name = item.name.toLowerCase();
                const whole = query.toLowerCase().trim();
                return name.startsWith(whole) ? 0 : (name.indexOf(whole) !== -1 ? 1 : 2);
            };
            const pick = (items, limit) => items
                .filter(item => words.every(word => item.haystack.indexOf(word) !== -1))
                .sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name))
                .slice(0, limit);
            if (!words.length) {
                return [['Pages', index.pages], ['Devices here now', index.devices.filter(d => d.here).slice(0, 8)]];
            }
            return [['Devices', pick(index.devices, 10)], ['Networks', pick(index.networks, 5)],
                    ['Pages', pick((index.menu || []).length ? index.menu : index.pages, 8)]];
        };

        const draw = () => {
            list.textContent = '';
            shown = [];
            if (!index) {
                list.appendChild(Object.assign(document.createElement('li'),
                    { className: 'lens-palette-group', textContent: 'Reading the devices...' }));
                return;
            }
            for (const [title, items] of find(input.value)) {
                if (!items.length) {
                    continue;
                }
                list.appendChild(Object.assign(document.createElement('li'),
                    { className: 'lens-palette-group', textContent: title }));
                for (const item of items) {
                    const position = shown.length;
                    const row = document.createElement('li');
                    row.className = 'lens-palette-item' + (position === cursor ? ' on' : '');
                    const icon = document.createElement('i');
                    icon.className = 'fa fa-fw ' + item.icon;
                    const name = document.createElement('div');
                    name.textContent = item.name;
                    const sub = document.createElement('div');
                    sub.className = 'sub';
                    sub.textContent = item.sub || '';
                    row.append(icon, name, sub);
                    row.addEventListener('mousemove', () => {
                        if (cursor !== position) {
                            cursor = position;
                            mark();
                        }
                    });
                    row.addEventListener('click', () => go(position));
                    list.appendChild(row);
                    shown.push({ item: item, row: row });
                }
            }
            if (!shown.length) {
                list.appendChild(Object.assign(document.createElement('li'), {
                    className: 'lens-palette-group',
                    textContent: index.failed ? 'The device list did not come back.' : 'Nothing matches.'
                }));
            }
        };

        const mark = () => {
            shown.forEach((entry, position) => entry.row.classList.toggle('on', position === cursor));
            if (shown[cursor]) {
                shown[cursor].row.scrollIntoView({ block: 'nearest' });
            }
        };

        const go = (position) => {
            if (shown[position]) {
                location.href = shown[position].item.url;
            }
        };

        const close = () => {
            if (root) {
                root.style.display = 'none';
                /* or the next "/" is typed into a box nobody can see */
                input.blur();
            }
        };

        const open = (from, text) => {
            if (!root) {
                root = document.createElement('div');
                root.className = 'lens-palette';
                root.innerHTML = '<div class="lens-palette-box" role="dialog" aria-label="Find">'
                    + '<input class="lens-palette-input" type="text" autocomplete="off" spellcheck="false"'
                    + ' placeholder="Search OPNsense: a page, a device, an address, a MAC, a network">'
                    + '<ul class="lens-palette-list"></ul>'
                    + '<div class="lens-palette-foot">\u2191\u2193 to move \u00b7 Enter to open \u00b7 Esc to close'
                    + '</div></div>';
                input = root.querySelector('input');
                list = root.querySelector('ul');
                root.addEventListener('click', (event) => {
                    if (event.target === root) {
                        close();
                    }
                });
                input.addEventListener('input', () => {
                    cursor = 0;
                    draw();
                });
                input.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        cursor = Math.max(0, Math.min(shown.length - 1, cursor + (event.key === 'ArrowDown' ? 1 : -1)));
                        mark();
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        go(cursor);
                    } else if (event.key === 'Escape') {
                        close();
                    }
                });
                document.body.appendChild(root);
            }
            root.style.display = '';
            input.value = text || '';
            cursor = 0;
            draw();
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            /* grown out of core's box at the top, so it reads as that box, larger */
            const panel = root.querySelector('.lens-palette-box');
            const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (from && panel.animate && !calm) {
                const to = panel.getBoundingClientRect();
                panel.animate([
                    { transformOrigin: 'top left', opacity: 0.4,
                      transform: 'translate(' + (from.left - to.left) + 'px,' + (from.top - to.top) + 'px) scale('
                          + (from.width / to.width) + ',' + Math.min(1, from.height / to.height) + ')' },
                    { transformOrigin: 'top left', opacity: 1, transform: 'none' }
                ], { duration: 220, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' });
                root.animate([{ backgroundColor: 'rgba(0, 0, 0, 0)' }, {}], { duration: 220 });
            }
            if (!index) {
                fetchIndex().then(draw);
            }
        };

        /* Ctrl-K or Cmd-K anywhere; "/" where it is not being typed. Core
           takes a, f and h without modifiers, and nothing here (§4.65). */
        document.addEventListener('keydown', (event) => {
            const typing = /^(input|textarea|select)$/i.test(event.target.tagName || '') || event.target.isContentEditable;
            const box = document.getElementById('menu_search_box');
            const from = box && box.offsetParent ? box.getBoundingClientRect() : null;
            if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
                event.preventDefault();
                open(from);
            } else if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                open(from);
            } else if (event.key === 'Escape' && root && root.style.display !== 'none') {
                close();
            }
        });

        /*
         * One search, not two (operator, 2026-10-07): on a Lens page core's own
         * box at the top is the way in. Focusing it opens this search grown out
         * of it -- core's whole menu plus the devices -- with whatever was
         * typed carried over. Elsewhere core's box is untouched: a plugin has
         * no way into it there (§4.79).
         */
        document.addEventListener('DOMContentLoaded', () => {
            const box = document.getElementById('menu_search_box');
            if (!box) {
                return;
            }
            box.setAttribute('placeholder', 'Search \u00b7 Ctrl K');
            box.addEventListener('focus', () => {
                const from = box.getBoundingClientRect();
                const text = box.value;
                box.value = '';
                box.blur();
                open(from, text);
            });
        });

        return { open: open, close: close };
    })();

    /*
     * A device's services (#44) as a row of chips, most asked first. What the
     * device asks by itself (platform) is drawn quieter than what someone chose.
     */
    const services = (list) => {
        const row = document.createElement('div');
        row.className = 'svc-chips';
        for (const service of list || []) {
            const chip = document.createElement('span');
            chip.className = 'svc-chip' + (service.platform ? ' svc-platform' : '')
                + (service.blocked ? ' svc-blocked' : '');
            const icon = document.createElement('i');
            icon.className = 'fa fa-fw ' + service.icon;
            const count = document.createElement('span');
            count.className = 'svc-count';
            count.textContent = service.queries;
            chip.append(icon, document.createTextNode(' ' + service.name + ' '), count);
            row.appendChild(chip);
        }
        return row;
    };

    /* a small bar chart of counts, oldest first: a service's day or week (#44) */
    const spark = (values, title) => {
        const NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(NS, 'svg');
        const list = values || [];
        const top = Math.max(1, ...list);
        svg.setAttribute('class', 'lens-spark');
        svg.setAttribute('viewBox', '0 0 ' + Math.max(1, list.length) + ' 20');
        svg.setAttribute('preserveAspectRatio', 'none');
        list.forEach((value, i) => {
            if (!value) {
                return;
            }
            const bar = document.createElementNS(NS, 'rect');
            const height = Math.max(1, value / top * 20);
            bar.setAttribute('x', i + 0.1);
            bar.setAttribute('width', 0.8);
            bar.setAttribute('y', 20 - height);
            bar.setAttribute('height', height);
            svg.appendChild(bar);
        });
        if (title) {
            const tip = document.createElementNS(NS, 'title');
            tip.textContent = title;
            svg.appendChild(tip);
        }
        return svg;
    };

    /*
     * The health row (§4.84): one sentence, then a tile per area. Shared by the
     * dashboard and the System page so the two cannot say different things.
     */
    const healthRow = (summaryEl, tilesEl, health, compact) => {
        summaryEl.className = 'health-summary health-' + health.summary.tone;
        summaryEl.textContent = '';
        const dot = document.createElement('span');
        dot.className = 'health-dot';
        const said = document.createElement(health.summary.link ? 'a' : 'span');
        if (health.summary.link) {
            said.href = health.summary.link;
        }
        said.textContent = health.summary.sentence;
        summaryEl.append(dot, said);

        tilesEl.textContent = '';
        /* the dashboard's strip (operator, 2026-10-09: eleven tiles read as
           bloat): only what is not all right, as chips, and the rest counted --
           the System page has every tile */
        if (compact) {
            tilesEl.className = 'health-chips';
            const troubled = health.tiles.filter(tile => tile.tone !== 'good');
            for (const tile of troubled) {
                const chip = document.createElement(tile.link ? 'a' : 'span');
                chip.className = 'health-chip health-' + tile.tone;
                if (tile.link) {
                    chip.href = tile.link;
                }
                chip.title = tile.sentence;
                const chipDot = document.createElement('span');
                chipDot.className = 'health-dot';
                const icon = document.createElement('i');
                icon.className = 'fa fa-fw ' + tile.icon;
                chip.append(chipDot, icon, document.createTextNode(' ' + tile.title));
                tilesEl.appendChild(chip);
            }
            const all = document.createElement('a');
            all.className = 'health-chip health-chip-all';
            all.href = '/ui/lens/system';
            const good = health.tiles.length - troubled.length;
            all.textContent = (troubled.length ? good + ' / ' + health.tiles.length + ' all right' : 'all '
                + health.tiles.length + ' areas all right') + ' \u00b7 System \u203a';
            tilesEl.appendChild(all);
            return;
        }
        tilesEl.className = 'health-tiles';
        for (const tile of health.tiles) {
            const box = document.createElement(tile.link ? 'a' : 'div');
            box.className = 'content-box health-tile health-' + tile.tone;
            if (tile.link) {
                box.href = tile.link;
            }
            const title = document.createElement('div');
            title.className = 'health-title';
            const icon = document.createElement('i');
            icon.className = 'fa fa-fw ' + tile.icon;
            const tileDot = document.createElement('span');
            tileDot.className = 'health-dot';
            title.append(icon, document.createTextNode(' ' + tile.title), tileDot);
            const sentence = document.createElement('div');
            sentence.className = 'health-sentence';
            sentence.textContent = tile.sentence;
            const detail = document.createElement('div');
            detail.className = 'dash-sub';
            detail.textContent = (tile.detail || []).join(' \u00b7 ');
            box.append(title, sentence, detail);
            tilesEl.appendChild(box);
        }
    };

    /*
     * A line chart over time (stage 53): one y-axis, at most four series in the
     * Lens order (--lens-cmp-1..4; more fold into "other"), a legend with each
     * series' latest value -- the visible label two light-theme colours need
     * below 3:1 -- and a crosshair whose tooltip lists every series at that time.
     *
     * series: [{key, points: [[unix, value|null]]}], unit: shown after values
     */
    const lines = (container, series, unit) => {
        const NS = 'http://www.w3.org/2000/svg';
        const W = 600;
        const H = 160;
        container.textContent = '';
        let list = (series || []).filter(s => s.points && s.points.some(p => p[1] !== null));
        if (!list.length) {
            const none = document.createElement('div');
            none.className = 'dash-sub';
            none.textContent = 'Nothing recorded in this range.';
            container.appendChild(none);
            return;
        }
        /* biggest first; past four, the rest summed as "other" */
        const mean = s => s.points.reduce((a, p) => a + (p[1] || 0), 0) / s.points.length;
        list.sort((a, b) => mean(b) - mean(a));
        if (list.length > 4) {
            const rest = list.slice(3);
            list = list.slice(0, 3).concat([{ key: 'other', other: true,
                points: rest[0].points.map((p, i) => [p[0], rest.reduce((a, s) => a + ((s.points[i] || [])[1] || 0), 0)]) }]);
        }
        const times = list[0].points.map(p => p[0]);
        const t0 = times[0];
        const t1 = times[times.length - 1] || t0 + 1;
        let top = 0;
        for (const s of list) {
            for (const p of s.points) {
                top = Math.max(top, p[1] || 0);
            }
        }
        top = top > 0 ? top * 1.08 : 1;
        const x = t => (t - t0) / Math.max(1, t1 - t0) * W;
        const y = v => H - v / top * H;

        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('class', 'lens-lines');
        for (const f of [0.5, 1]) {
            const grid = document.createElementNS(NS, 'line');
            grid.setAttribute('x1', 0);
            grid.setAttribute('x2', W);
            grid.setAttribute('y1', H - f * H / 1.08);
            grid.setAttribute('y2', H - f * H / 1.08);
            grid.setAttribute('class', 'lens-lines-grid');
            svg.appendChild(grid);
        }
        list.forEach((s, i) => {
            let d = '';
            let pen = false;
            for (const p of s.points) {
                if (p[1] === null) {
                    pen = false;
                    continue;
                }
                d += (pen ? 'L' : 'M') + x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1);
                pen = true;
            }
            const path = document.createElementNS(NS, 'path');
            path.setAttribute('d', d);
            path.setAttribute('class', 'lens-lines-path ' + (s.other ? 'lens-lines-other' : 'cmp-s' + (i + 1)));
            svg.appendChild(path);
        });
        const cross = document.createElementNS(NS, 'line');
        cross.setAttribute('y1', 0);
        cross.setAttribute('y2', H);
        cross.setAttribute('class', 'lens-lines-cross');
        cross.style.display = 'none';
        svg.appendChild(cross);

        const fmt = v => v === null || v === undefined ? '\u2014'
            : (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10) + (unit ? ' ' + unit : '');
        tip(svg, (event) => {
            const r = svg.getBoundingClientRect();
            const at = t0 + (event.clientX - r.left) / r.width * (t1 - t0);
            let i = 0;
            while (i < times.length - 1 && Math.abs(times[i + 1] - at) < Math.abs(times[i] - at)) {
                i++;
            }
            cross.setAttribute('x1', x(times[i]));
            cross.setAttribute('x2', x(times[i]));
            cross.style.display = '';
            return [{ value: when(times[i], 60, true) }].concat(list.map((s, n) => ({
                key: getComputedStyle(svg.querySelectorAll('path')[n]).stroke,
                value: fmt((s.points[i] || [])[1]), label: s.key })));
        });
        svg.addEventListener('pointerleave', () => {
            cross.style.display = 'none';
        });

        const legend = document.createElement('div');
        legend.className = 'lens-lines-legend';
        list.forEach((s, i) => {
            const last = [...s.points].reverse().find(p => p[1] !== null);
            const item = document.createElement('span');
            const key = document.createElement('span');
            key.className = 'cmp-key ' + (s.other ? 'lens-lines-other-key' : 'cmp-s' + (i + 1));
            item.append(key, document.createTextNode(s.key + ' '));
            const value = document.createElement('b');
            value.textContent = fmt(last ? last[1] : null);
            item.appendChild(value);
            legend.appendChild(item);
        });
        const scale = document.createElement('div');
        scale.className = 'lens-lines-scale';
        scale.textContent = when(t0, 3600, true) + ' \u2013 ' + when(t1, 3600, true) + ' \u00b7 top ' + fmt(top / 1.08);
        container.append(legend, svg, scale);
    };

    /*
     * A button that is working says so (operator, 2026-10-09: a pause "dauert
     * bissle und man hat kein Wissen, was passiert"): disabled, a spinner and
     * what it is doing, until the answer is there; then itself again.
     */
    const busy = (button, on, doing) => {
        if (!button) {
            return;
        }
        if (on) {
            if (button.dataset.lensIdle === undefined) {
                button.dataset.lensIdle = button.innerHTML;
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = '';
            const spin = document.createElement('i');
            spin.className = 'fa fa-spinner fa-spin';
            button.append(spin, document.createTextNode(' ' + (doing || '') + '\u2026'));
        } else if (button.dataset.lensIdle !== undefined) {
            button.innerHTML = button.dataset.lensIdle;
            delete button.dataset.lensIdle;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    };

    /*
     * What a page showed last in this session, shown again at once while the
     * fresh answer is on its way (stale, then replaced): the health row is a
     * dozen reads on a busy box, and a blank second reads as broken.
     */
    const remembered = (key, url, draw) => {
        let shown = false;
        try {
            const kept = JSON.parse(sessionStorage.getItem('lens.kept.' + key) || 'null');
            if (kept) {
                draw(kept, true);
                shown = true;
            }
        } catch (e) {
            /* nothing kept */
        }
        return fetch(url, { credentials: 'same-origin' })
            .then(reply => reply.ok ? reply.json() : Promise.reject(reply.status))
            .then((fresh) => {
                try {
                    sessionStorage.setItem('lens.kept.' + key, JSON.stringify(fresh));
                } catch (e) {
                    /* this page only */
                }
                draw(fresh, false);
                return fresh;
            })
            .catch((failure) => {
                if (!shown) {
                    throw failure;
                }
            });
    };

    window.Lens = {
        theme: theme, tip: tip, hideTip: hide, when: when, axis: axis, filter: filter, palette: palette,
        services: services, spark: spark, healthRow: healthRow, lines: lines,
        display: display, time: time, date: date, stamp: stamp, busy: busy, remembered: remembered
    };
})();
