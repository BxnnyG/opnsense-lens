{#
 # Copyright (C) 2026 Benny <claude@bxnny.de>
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1.  Redistributions of source code must retain the above copyright notice,
 #     this list of conditions and the following disclaimer.
 #
 # 2.  Redistributions in binary form must reproduce the above copyright notice,
 #     this list of conditions and the following disclaimer in the documentation
 #     and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<style>
    /*
     * One screen, one glance. Every card answers one question and opens the
     * page that answers it in full; nothing here is computed that another page
     * does not also show (§4.37).
     */
    .dash-head { display: flex; flex-wrap: wrap; gap: 14px; align-items: center;
                 justify-content: space-between; margin-bottom: 12px; }
    .dash-grid { display: grid; gap: 14px;
                 grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); }
    .dash-wide { grid-column: 1 / -1; }
    .dash-card { padding: 14px 16px; margin: 0; }
    .dash-title { font-size: 11px; font-weight: 600; letter-spacing: 0.06em;
                  text-transform: uppercase; color: #999; margin: 0 0 10px 0;
                  display: flex; justify-content: space-between; }
    .dash-title a { text-transform: none; letter-spacing: 0; font-weight: normal; }

    .dash-facts { display: grid; gap: 14px;
                  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
    .dash-fact { padding: 14px 16px; margin: 0; display: flex; gap: 14px; align-items: center; }
    .dash-fact i { font-size: 26px; color: #d94f00; width: 30px; text-align: center; }
    .dash-num { font-size: 26px; font-weight: 600; line-height: 1.05;
                font-variant-numeric: tabular-nums; }
    .dash-sub { color: #999; font-size: 12px; }
    .dash-warn { color: #f0ad4e; }

    .dash-sentence { display: flex; gap: 16px; align-items: center;
                     padding: 16px 18px; margin: 0 0 14px 0;
                     border-left: 4px solid #5cb85c; }
    .dash-sentence > i { font-size: 30px; color: #5cb85c; }
    .dash-sentence-text { font-size: 18px; line-height: 1.3; }
    .dash-sentence.dash-notice { border-left-color: #f0ad4e; }
    .dash-sentence.dash-notice > i { color: #f0ad4e; }
    .dash-sentence.dash-alert { border-left-color: #d9534f; }
    .dash-sentence.dash-alert > i { color: #d9534f; }

    .dash-chart { width: 100%; height: 170px; display: block; }
    .dash-up { fill: #d94f00; opacity: 0.85; }
    .dash-down { fill: #7a8b99; opacity: 0.75; }
    .dash-axis { fill: #999; font-size: 10px; }
    .dash-legend { color: #999; font-size: 12px; margin-top: 6px; }
    .dash-key { display: inline-block; width: 9px; height: 9px; border-radius: 2px;
                margin: 0 3px 0 10px; }

    .dash-row { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
    .dash-row .name { flex: 0 0 42%; overflow: hidden; text-overflow: ellipsis;
                      white-space: nowrap; }
    .dash-row .track { flex: 1; height: 8px; border-radius: 4px;
                       background: rgba(128, 128, 128, 0.18); }
    .dash-row .fill { height: 8px; border-radius: 4px; background: #d94f00; }
    .dash-row .val { flex: 0 0 5.5em; text-align: right; font-variant-numeric: tabular-nums; }

    .dash-donut-wrap { display: flex; gap: 16px; align-items: center; }
    .dash-donut { width: 130px; height: 130px; flex: 0 0 130px; }
    .dash-slices { flex: 1; font-size: 12px; }
    .dash-slice { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }

    .dash-meter { margin-bottom: 12px; }
    .dash-meter .label { display: flex; justify-content: space-between; font-size: 12px; }
    .dash-meter .track { height: 8px; border-radius: 4px; margin-top: 4px;
                         background: rgba(128, 128, 128, 0.18); }
    .dash-meter .fill { height: 8px; border-radius: 4px; background: #5cb85c; }
    .dash-meter .fill.hot { background: #f0ad4e; }
    .dash-meter .fill.full { background: #d9534f; }

    .lens-chip { display: inline-block; padding: 1px 8px; margin: 2px 3px;
                 border: 1px solid #999; border-radius: 10px; font-size: 90%;
                 text-decoration: none; }
    .lens-chip-on { border-color: #d94f00; color: #d94f00; font-weight: 600; }
</style>

<script>
    $(document).ready(() => {
        const LABELS = { 24: '{{ lang._("24 hours") }}',
                         168: '{{ lang._("7 days") }}',
                         720: '{{ lang._("30 days") }}' };
        const PALETTE = ['#d94f00', '#e8833a', '#7a8b99', '#5b8fb9', '#8aa66a',
                         '#b9895b', '#9c6fb0', '#c8b35a', '#5fa39b', '#999999'];

        const hours = (() => {
            const asked = parseInt(new URLSearchParams(location.search).get('hours'), 10);
            return LABELS[asked] ? asked : 24;
        })();

        const bytes = (octets) => {
            if (!octets) {
                return '0 B';
            }
            if (octets < 1024) {
                return octets + ' B';
            }
            const units = ['KB', 'MB', 'GB', 'TB'];
            let value = octets / 1024;
            for (let i = 0; i < units.length; i++) {
                if (value < 1024 || i === units.length - 1) {
                    return (value < 10 ? value.toFixed(1) : Math.round(value)) + ' ' + units[i];
                }
                value /= 1024;
            }
        };

        const svg = (tag, attrs) => {
            const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
            for (const [key, value] of Object.entries(attrs || {})) {
                node.setAttribute(key, value);
            }
            return node;
        };

        const range = () => {
            const $bar = $('#dashRange').empty();
            for (const h of [24, 168, 720]) {
                $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', h === hours)
                    .attr('href', location.pathname + '?hours=' + h).text(LABELS[h])
                    .appendTo($bar);
            }
        };

        const link = (path) => path + '?hours=' + hours;

        /* ------------------------------------------------ facts and devices */
        ajaxGet('/api/lens/devices/list', { hours: hours }, (report, status) => {
            if (status !== 'success' || !report || !report.devices) {
                $('#dashError').show();
                return;
            }

            /* the one line for someone who reads only one line (§4.53) */
            const sentence = report.sentence || {};
            if (sentence.sentence) {
                $('#dashSentence').attr('class', 'content-box dash-sentence dash-' + sentence.tone);
                $('#dashSentenceIcon').attr('class', 'fa ' + sentence.icon);
                $('#dashSentenceText').text(sentence.sentence);
                $('#dashSentenceAlso').text((sentence.also || []).join(' '));
                $('#dashSentence').show();
            }

            const summary = report.summary || {};
            $('#factHere').text((summary.here || 0) + ' / ' + (summary.known || 0));
            $('#factMoved').text(summary.moved || '0 B');
            $('#factMovedSub').text('{{ lang._("attributed over") }} ' + LABELS[hours]);

            if (summary.new_yet) {
                $('#factNew').text((summary.new || []).length)
                    .toggleClass('dash-warn', (summary.new || []).length > 0);
                $('#factNewSub').text((summary.new || []).slice(0, 2).join(', ')
                    || '{{ lang._("new in 24 hours") }}');
            } else {
                $('#factNew').text(summary.watching_for || '');
                $('#factNewSub').text('{{ lang._("watching so far") }}');
            }

            const baseline = report.baseline || {};
            if (baseline.learning) {
                $('#factUnusual').text(baseline.days + ' / ' + baseline.needs_days);
                $('#factUnusualSub').text('{{ lang._("days learned, then it can judge") }}');
            } else {
                const unusual = baseline.unusual || [];
                let sub = '{{ lang._("nothing unusual today") }}';
                if (unusual.length === 1) {
                    sub = unusual[0].name;
                } else if (unusual.length > 1) {
                    sub = unusual[0].name + ' {{ lang._("and") }} ' + (unusual.length - 1)
                        + ' {{ lang._("more") }}';
                }
                $('#factUnusual').text(unusual.length).toggleClass('dash-warn', unusual.length > 0);
                $('#factUnusualSub').text(sub);
            }

            /* the herd folded exactly as the Devices page folds it (§4.48) */
            const groups = new Map((report.groups || []).map(g => [g.key, g]));
            const folded = new Map();
            const rows = [];
            for (const device of report.devices) {
                const group = groups.get(device.group);
                if (!group) {
                    rows.push({ name: device.name, icon: device.kind.icon, octets: device.octets });
                    continue;
                }
                if (!folded.has(group.key)) {
                    folded.set(group.key, { name: group.label + ' × ' + group.count,
                                            icon: group.icon, octets: 0 });
                    rows.push(folded.get(group.key));
                }
                folded.get(group.key).octets += device.octets;
            }
            rows.sort((a, b) => b.octets - a.octets);

            const top = rows.filter(r => r.octets > 0).slice(0, 7);
            const largest = top.length ? top[0].octets : 0;
            const $top = $('#dashTop').empty();
            for (const row of top) {
                $top.append($('<div/>').addClass('dash-row')
                    .append($('<div/>').addClass('name').attr('title', row.name)
                        .append($('<i/>').addClass('fa fa-fw ' + row.icon))
                        .append(document.createTextNode(' ' + row.name)))
                    .append($('<div/>').addClass('track').append($('<div/>').addClass('fill')
                        .css('width', largest ? Math.max(2, row.octets / largest * 100) + '%' : 0)))
                    .append($('<div/>').addClass('val').text(bytes(row.octets))));
            }
            if (!top.length) {
                $top.append($('<div/>').addClass('dash-sub')
                    .text('{{ lang._("No device has attributed traffic in this window yet.") }}'));
            }

            $('#dashReady').show();
        });

        /* ------------------------------------------------ the network over time */
        ajaxGet('/api/lens/dashboard/timeline', { hours: hours }, (data, status) => {
            if (status !== 'success' || !data || !data.series) {
                return;
            }

            const chart = document.getElementById('dashChart');
            const width = 800;
            const height = 170;
            const pad = 16;
            const points = data.series;
            const peak = data.peak || 1;
            chart.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
            while (chart.firstChild) {
                chart.removeChild(chart.firstChild);
            }

            if (!points.length) {
                return;
            }

            /* two stacked areas rather than bars: over thirty days a bar chart
               is a comb, and the shape of the week is what the eye wants */
            const x = (i) => points.length === 1 ? width / 2 : i / (points.length - 1) * width;
            const y = (v) => height - pad - (v / peak) * (height - pad * 2);

            const area = (top, bottom) => {
                let path = 'M ' + x(0) + ' ' + y(bottom(points[0]));
                points.forEach((p, i) => { path += ' L ' + x(i) + ' ' + y(top(p)); });
                for (let i = points.length - 1; i >= 0; i--) {
                    path += ' L ' + x(i) + ' ' + y(bottom(points[i]));
                }
                return path + ' Z';
            };

            chart.appendChild(svg('path', {
                d: area(p => p.sent + p.received, p => p.sent), class: 'dash-down'
            }));
            chart.appendChild(svg('path', {
                d: area(p => p.sent, () => 0), class: 'dash-up'
            }));

            const label = svg('text', { x: 4, y: 12, class: 'dash-axis' });
            label.textContent = data.peak_text + ' {{ lang._("peak per") }} '
                + (data.step >= 86400 ? '{{ lang._("day") }}' : '{{ lang._("hour") }}');
            chart.appendChild(label);

            $('#dashChartNote').toggle(!!(data.window || {}).note)
                .text((data.window || {}).note || '');
        });

        /* ------------------------------------------------ networks as a donut */
        ajaxGet('/api/lens/segments/list', { hours: hours }, (report, status) => {
            if (status !== 'success' || !report || !report.segments) {
                return;
            }

            /* only your own networks; the far side of the line is not a share
               of anything you own */
            const mine = report.segments.filter(s => s.is_network && s.octets > 0);
            const total = mine.reduce((sum, s) => sum + s.octets, 0);
            const donut = document.getElementById('dashDonut');
            while (donut.firstChild) {
                donut.removeChild(donut.firstChild);
            }
            const $list = $('#dashSlices').empty();

            if (!total) {
                $list.append($('<div/>').addClass('dash-sub')
                    .text('{{ lang._("No traffic on your own networks in this window yet.") }}'));
                return;
            }

            const shown = mine.slice(0, 7);
            const rest = mine.slice(7).reduce((sum, s) => sum + s.octets, 0);
            if (rest) {
                shown.push({ name: '{{ lang._("everything else") }}', octets: rest });
            }

            let angle = -Math.PI / 2;
            shown.forEach((slice, index) => {
                const share = slice.octets / total;
                const next = angle + share * Math.PI * 2;
                const colour = PALETTE[index % PALETTE.length];
                const large = share > 0.5 ? 1 : 0;
                const r = 60;
                const inner = 38;
                const pt = (a, radius) => (65 + radius * Math.cos(a)) + ' ' + (65 + radius * Math.sin(a));

                const d = share >= 0.999
                    ? 'M 65 5 A 60 60 0 1 1 64.99 5 L 64.99 27 A 38 38 0 1 0 65 27 Z'
                    : 'M ' + pt(angle, r) + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + pt(next, r)
                      + ' L ' + pt(next, inner) + ' A ' + inner + ' ' + inner + ' 0 ' + large
                      + ' 0 ' + pt(angle, inner) + ' Z';

                const path = svg('path', { d: d, fill: colour });
                const title = svg('title');
                title.textContent = slice.name + ' — ' + bytes(slice.octets);
                path.appendChild(title);
                donut.appendChild(path);
                angle = next;

                $list.append($('<div/>').addClass('dash-slice')
                    .append($('<span/>')
                        .append($('<span/>').addClass('dash-key').css('background', colour))
                        .append(document.createTextNode(slice.name)))
                    .append($('<span/>').text(Math.round(share * 100) + '%')));
            });
        });

        /* ------------------------------------------------ the firewall itself */
        const meter = (id, percent, text) => {
            const $meter = $('#' + id);
            if (percent === null || percent === undefined) {
                $meter.hide();
                return;
            }
            $meter.find('.value').text(text);
            $meter.find('.fill').css('width', Math.min(100, percent) + '%')
                .toggleClass('hot', percent >= 75).toggleClass('full', percent >= 90);
            $meter.show();
        };

        let lastWan = null;

        const system = () => ajaxGet('/api/lens/dashboard/system', {}, (sys, status) => {
            if (status !== 'success' || !sys) {
                return;
            }

            if (sys.load) {
                meter('meterLoad', sys.load.percent,
                      sys.load.one.toFixed(2) + ' {{ lang._("on") }} ' + sys.load.cores
                      + ' {{ lang._("cores") }}');
            }
            if (sys.memory) {
                meter('meterMem', sys.memory.percent, sys.memory.text);
            }
            if (sys.disk) {
                meter('meterDisk', sys.disk.percent, sys.disk.text);
            }
            $('#sysUptime').text(sys.uptime ? sys.uptime.text : '');

            /* the WAN rate needs two readings of core's cumulative counters;
               the first poll only remembers, every later one can say */
            if (sys.wan) {
                $('#factWanName').text(sys.wan.name);
                if (lastWan && sys.wan.at > lastWan.at) {
                    const seconds = sys.wan.at - lastWan.at;
                    const down = Math.max(0, sys.wan.received - lastWan.received) * 8 / seconds;
                    const up = Math.max(0, sys.wan.sent - lastWan.sent) * 8 / seconds;
                    const bits = (b) => b >= 1e9 ? (b / 1e9).toFixed(1) + ' Gbit/s'
                        : b >= 1e6 ? (b / 1e6).toFixed(1) + ' Mbit/s'
                        : Math.round(b / 1e3) + ' kbit/s';
                    $('#factWan').text('↓ ' + bits(down));
                    $('#factWanSub').text('↑ ' + bits(up) + ' {{ lang._("right now") }}');
                } else {
                    $('#factWan').text('…');
                    $('#factWanSub').text('{{ lang._("measuring") }}');
                }
                lastWan = sys.wan;
            }
        });

        range();
        system();
        setInterval(system, 5000);
    });
</script>

<div class="dash-head">
    <div>{{ lang._('Your network at a glance. Every card opens the page that answers it in full.') }}</div>
    <div id="dashRange"></div>
</div>

<div id="dashSentence" class="content-box dash-sentence" style="display: none;">
    <i id="dashSentenceIcon"></i>
    <div>
        <div id="dashSentenceText" class="dash-sentence-text"></div>
        <div id="dashSentenceAlso" class="dash-sub"></div>
    </div>
</div>

<div id="dashError" class="alert alert-danger" style="display: none;">
    {{ lang._('The device report did not come back.') }}
</div>

<div class="dash-facts">
    <a class="content-box dash-fact" href="/ui/lens/presence" style="color: inherit; text-decoration: none;">
        <i class="fa fa-home"></i>
        <div><div class="dash-num" id="factHere">&hellip;</div>
             <div class="dash-sub">{{ lang._('devices home now, of all known') }} &rsaquo;</div></div>
    </a>
    <div class="content-box dash-fact">
        <i class="fa fa-exchange"></i>
        <div><div class="dash-num" id="factMoved">&hellip;</div>
             <div class="dash-sub" id="factMovedSub"></div></div>
    </div>
    <div class="content-box dash-fact">
        <i class="fa fa-globe"></i>
        <div><div class="dash-num" id="factWan">&hellip;</div>
             <div class="dash-sub"><span id="factWanName">WAN</span> &middot;
                 <span id="factWanSub"></span></div></div>
    </div>
    <div class="content-box dash-fact">
        <i class="fa fa-bell-o"></i>
        <div><div class="dash-num" id="factUnusual">&hellip;</div>
             <div class="dash-sub" id="factUnusualSub"></div></div>
    </div>
    <div class="content-box dash-fact">
        <i class="fa fa-user-plus"></i>
        <div><div class="dash-num" id="factNew">&hellip;</div>
             <div class="dash-sub" id="factNewSub"></div></div>
    </div>
</div>

<div class="dash-grid" style="margin-top: 14px;">
    <div class="content-box dash-card dash-wide">
        <div class="dash-title">
            <span>{{ lang._('Your networks over time') }}</span>
            <a href="/ui/lens/segments">{{ lang._('per network') }} &rsaquo;</a>
        </div>
        <svg id="dashChart" class="dash-chart" preserveAspectRatio="none"></svg>
        <div class="dash-legend">
            <span class="dash-key" style="background:#d94f00"></span>{{ lang._('sent by your devices') }}
            <span class="dash-key" style="background:#7a8b99"></span>{{ lang._('received') }}
            <span id="dashChartNote" style="display:none; margin-left: 10px;"></span>
        </div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title">
            <span>{{ lang._('Who is using the line') }}</span>
            <a href="/ui/lens/overview">{{ lang._('all devices') }} &rsaquo;</a>
        </div>
        <div id="dashTop"></div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title">
            <span>{{ lang._('Which networks') }}</span>
            <a href="/ui/lens/segments">{{ lang._('details') }} &rsaquo;</a>
        </div>
        <div class="dash-donut-wrap">
            <svg id="dashDonut" class="dash-donut" viewBox="0 0 130 130"></svg>
            <div id="dashSlices" class="dash-slices"></div>
        </div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title">
            <span>{{ lang._('This firewall') }}</span>
            <span class="dash-sub">{{ lang._('up') }} <span id="sysUptime"></span></span>
        </div>
        <div class="dash-meter" id="meterLoad" style="display:none;">
            <div class="label"><span>{{ lang._('Load') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-meter" id="meterMem" style="display:none;">
            <div class="label"><span>{{ lang._('Memory') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-meter" id="meterDisk" style="display:none;">
            <div class="label"><span>{{ lang._('Disk') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-sub">{{ lang._('Computed exactly as OPNsense\'s own system widgets compute them.') }}</div>
    </div>
</div>
