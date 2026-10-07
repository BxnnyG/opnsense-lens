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

    const formats = {};
    const format = (options) => {
        const key = JSON.stringify(options);
        if (!formats[key]) {
            formats[key] = new Intl.DateTimeFormat(undefined, options);
        }
        return formats[key];
    };

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

    const INDEX_KEY = 'lens.index';
    const INDEX_FOR = 5 * 60 * 1000;
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
            return fetch('/api/lens/devices/index', { credentials: 'same-origin' })
                .then(reply => reply.ok ? reply.json() : Promise.reject(reply.status))
                .then((data) => {
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
            return [['Devices', pick(index.devices, 12)], ['Networks', pick(index.networks, 5)],
                    ['Pages', pick(index.pages, 5)]];
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

        const open = () => {
            if (!root) {
                root = document.createElement('div');
                root.className = 'lens-palette';
                root.innerHTML = '<div class="lens-palette-box" role="dialog" aria-label="Find">'
                    + '<input class="lens-palette-input" type="text" autocomplete="off" spellcheck="false"'
                    + ' placeholder="A device, an address, a MAC, a network or a page">'
                    + '<ul class="lens-palette-list"></ul>'
                    + '<div class="lens-palette-foot">\u2191\u2193 to move \u00b7 Enter to open \u00b7 Esc to close'
                    + ' \u00b7 core\'s own pages: the menu search at the top</div></div>';
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
            input.value = '';
            cursor = 0;
            draw();
            input.focus();
            if (!index) {
                fetchIndex().then(draw);
            }
        };

        /* Ctrl-K or Cmd-K anywhere; "/" where it is not being typed. Core
           takes a, f and h without modifiers, and nothing here (§4.65). */
        document.addEventListener('keydown', (event) => {
            const typing = /^(input|textarea|select)$/i.test(event.target.tagName || '') || event.target.isContentEditable;
            if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
                event.preventDefault();
                open();
            } else if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                open();
            } else if (event.key === 'Escape' && root && root.style.display !== 'none') {
                close();
            }
        });

        /* the way in for a mouse: a hint beside the page's title */
        document.addEventListener('DOMContentLoaded', () => {
            const head = document.querySelector('.page-content-head .list-inline');
            if (!head || document.querySelector('.lens-palette-hint')) {
                return;
            }
            const hint = document.createElement('li');
            hint.className = 'lens-palette-hint';
            hint.innerHTML = '<a href="#"><i class="fa fa-search"></i> Find a device <kbd>Ctrl</kbd> <kbd>K</kbd></a>';
            hint.firstChild.addEventListener('click', (event) => {
                event.preventDefault();
                open();
            });
            head.appendChild(hint);
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

    window.Lens = {
        theme: theme, tip: tip, hideTip: hide, when: when, axis: axis, filter: filter, palette: palette,
        services: services
    };
})();
