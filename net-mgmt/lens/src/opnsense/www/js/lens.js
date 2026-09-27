/*
 * Lens -- the little every page shares (DESIGN §4.59). Layout only: what a
 * number means is decided in PHP or Python, never here (§4.21).
 *
 *   Lens.theme()        which of core's themes is showing
 *   Lens.tip(el, fn)    a tooltip whose lines fn() returns, on hover and focus
 *   Lens.when(at, step) a time label in the reader's own locale
 *   Lens.axis(...)      time labels under an SVG chart
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

    window.Lens = { theme: theme, tip: tip, hideTip: hide, when: when, axis: axis };
})();
