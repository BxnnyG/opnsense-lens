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

<link rel="stylesheet" type="text/css" href="{{ cache_safe('/ui/css/lens.css') }}">
<script src="{{ cache_safe('/ui/js/lens.js') }}"></script>

<script>
    $(document).ready(() => {
        const LABELS = { 24: '{{ lang._("24 hours") }}',
                         168: '{{ lang._("7 days") }}',
                         720: '{{ lang._("30 days") }}' };

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
            const compare = report.compare || {};
            $('#factMovedSub').text('{{ lang._("attributed over") }} ' + LABELS[hours]
                + (compare.covered && compare.total && compare.total.text
                    ? ' \u00b7 ' + compare.total.text + ' ' + compare.label : ''));

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
                    rows.push({ name: device.name, icon: device.kind.icon, octets: device.octets,
                                href: '/ui/lens/device?mac=' + encodeURIComponent(device.mac) });
                    continue;
                }
                if (!folded.has(group.key)) {
                    folded.set(group.key, { name: group.label + ' × ' + group.count,
                                            icon: group.icon, octets: 0,
                                            href: '/ui/lens/overview?hours=' + hours });
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
                    .append($('<a/>').addClass('name').attr('title', row.name)
                        .attr('href', row.href).css('color', 'inherit')
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
            const height = 180;
            const pad = 22;
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
            const y = (v) => height - 1 - (v / peak) * (height - 1 - pad);

            chart.appendChild(svg('line', { x1: 0, x2: width, y1: y(0), y2: y(0), class: 'lens-gridline' }));
            chart.appendChild(svg('line', { x1: 0, x2: width, y1: y(peak), y2: y(peak), class: 'lens-gridline' }));

            const area = (top, bottom) => {
                let path = 'M ' + x(0) + ' ' + y(bottom(points[0]));
                points.forEach((p, i) => { path += ' L ' + x(i) + ' ' + y(top(p)); });
                for (let i = points.length - 1; i >= 0; i--) {
                    path += ' L ' + x(i) + ' ' + y(bottom(points[i]));
                }
                return path + ' Z';
            };

            const line = (top) => points.map((p, i) => (i ? 'L ' : 'M ') + x(i) + ' ' + y(top(p))).join(' ');
            chart.appendChild(svg('path', {
                d: area(p => p.sent + p.received, p => p.sent), class: 'dash-down'
            }));
            chart.appendChild(svg('path', {
                d: area(p => p.sent, () => 0), class: 'dash-up'
            }));
            chart.appendChild(svg('path', { d: line(p => p.sent + p.received), class: 'lens-received-line' }));
            chart.appendChild(svg('path', { d: line(p => p.sent), class: 'lens-sent-line' }));

            $('#dashChartPeak').text(data.peak_text + ' {{ lang._("peak per") }} '
                + (data.step >= 86400 ? '{{ lang._("day") }}' : '{{ lang._("hour") }}'));
            Lens.axis(document.getElementById('dashChartAxis'), points,
                      (i) => points.length === 1 ? 0.5 : i / (points.length - 1), data.step);

            /* the crosshair finds the slice; one readout carries both directions */
            const cross = svg('line', { x1: 0, x2: 0, y1: pad, y2: y(0), class: 'lens-crosshair',
                                        visibility: 'hidden' });
            chart.appendChild(cross);
            const hit = svg('rect', { x: 0, y: 0, width: width, height: height, fill: 'transparent' });
            chart.appendChild(hit);
            Lens.tip(hit, (event) => {
                const box = chart.getBoundingClientRect();
                const i = Math.max(0, Math.min(points.length - 1,
                    Math.round((event.clientX - box.left) / box.width * (points.length - 1))));
                cross.setAttribute('x1', x(i));
                cross.setAttribute('x2', x(i));
                cross.setAttribute('visibility', 'visible');
                const p = points[i];
                return [
                    { label: Lens.when(p.at, data.step, true) },
                    { value: bytes(p.received), label: '{{ lang._("received") }}', key: 'var(--lens-received)' },
                    { value: bytes(p.sent), label: '{{ lang._("sent by your devices") }}', key: 'var(--lens-sent)' },
                ];
            });
            hit.addEventListener('pointerleave', () => cross.setAttribute('visibility', 'hidden'));

            $('#dashChartNote').toggle(!!(data.window || {}).note)
                .text((data.window || {}).note || '');
        });

        /* ------------------------------------------------ networks, one bar each */
        ajaxGet('/api/lens/segments/list', { hours: hours }, (report, status) => {
            if (status !== 'success' || !report || !report.segments) {
                return;
            }

            /* only your own networks; the far side of the line is not a share
               of anything you own. One series, so one colour: the bar length is
               the comparison, and a hue per network would only repeat the name
               beside it (and was coloured by rank, so it moved with the range) */
            const mine = report.segments.filter(s => s.is_network && s.octets > 0);
            const total = mine.reduce((sum, s) => sum + s.octets, 0);
            const $list = $('#dashNetworks').empty();

            if (!total) {
                $list.append($('<div/>').addClass('dash-sub')
                    .text('{{ lang._("No traffic on your own networks in this window yet.") }}'));
                return;
            }

            const largest = mine[0].octets;
            for (const network of mine.slice(0, 7)) {
                const share = Math.round(network.octets / total * 100);
                $list.append($('<div/>').addClass('dash-row')
                    .append($('<a/>').addClass('name').attr('title', network.name)
                        .attr('href', '/ui/lens/overview?hours=' + hours
                              + '&segment=' + encodeURIComponent(network.interface))
                        .text(network.name))
                    .append($('<div/>').addClass('track').append($('<div/>').addClass('fill')
                        .css('width', Math.max(2, network.octets / largest * 100) + '%')))
                    .append($('<div/>').addClass('val').text(share + '%')
                        .attr('title', network.traffic)));
            }
            if (mine.length > 7) {
                $list.append($('<div/>').addClass('dash-sub').text(
                    (mine.length - 7) + ' {{ lang._("more on Networks") }}'));
            }
        });

        /* ------------------------------------------------ the network's week */
        ajaxGet('/api/lens/dashboard/heatmap', {}, (grid, status) => {
            if (status !== 'success' || !grid || !grid.rows) {
                return;
            }
            const $hm = $('#dashHeatmap').empty();
            $hm.append($('<div/>'));
            for (let hour = 0; hour < 24; hour++) {
                $hm.append($('<div/>').addClass('hm-hour').text(hour % 3 === 0 ? hour : ''));
            }
            grid.rows.forEach((row, day) => {
                $hm.append($('<div/>').addClass('hm-day').text(grid.days[day]));
                row.forEach((cell, hour) => {
                    const $cell = $('<div/>').addClass('hm-cell hm-l' + cell.level)
                        .attr('aria-label', grid.days[day] + ' ' + hour + ':00, ' + cell.text);
                    Lens.tip($cell[0], [{ value: cell.text, label: grid.days[day] + ' ' + hour + ':00' }]);
                    $hm.append($cell);
                });
            });
            $('#dashHeatmapNote').text(grid.empty
                ? '{{ lang._("Nothing measured yet.") }}'
                : '{{ lang._("Your networks, four weeks folded onto one, local time. Busiest:") }} '
                  + (grid.busiest ? grid.days[grid.busiest.day] + ' ' + grid.busiest.hour + ':00' : ''));
        });

        /* ------------------------------------------------ the internet */
        const probeSpark = (series) => {
            const node = svg('svg', { viewBox: '0 0 120 28', preserveAspectRatio: 'none',
                                      class: 'net-spark' });
            const values = series.map(p => p.rtt).filter(v => v !== null);
            if (series.length < 2 || !values.length) {
                return node;
            }
            const peak = Math.max(1, ...values);
            const x = (i) => i / (series.length - 1) * 120;
            let path = '';
            series.forEach((p, i) => {
                /* a slice where nothing answered is drawn as a red bar, not a
                   dip to zero: "no answer" is not "fast" */
                if (p.loss >= 100) {
                    node.appendChild(svg('rect', { x: x(i) - 1, y: 0, width: 2, height: 28,
                                                   class: 'net-gap' }));
                }
                if (p.rtt === null) {
                    return;
                }
                path += (path ? ' L ' : 'M ') + x(i) + ' ' + (26 - p.rtt / peak * 22);
            });
            node.appendChild(svg('path', { d: path, class: 'net-spark-line' }));
            return node;
        };

        ajaxGet('/api/lens/dashboard/internet', { hours: hours }, (net, status) => {
            if (status !== 'success' || !net || !net.state) {
                return;
            }

            $('#netPanel').attr('class', 'content-box net-panel net-' + net.state.key).show();
            $('#netState').text(net.state.text);
            $('#netWanName').text(net.wan.name);
            $('#netV4').text(net.wan.ipv4 || '{{ lang._("no IPv4") }}');
            $('#netV6').text(net.wan.ipv6 || '').toggle(!!net.wan.ipv6);

            const $probes = $('#netProbes').empty();
            for (const probe of net.probes) {
                const $p = $('<div/>').addClass('net-probe');
                $p.append($('<div/>').addClass('dash-sub').text(probe.target));
                $p.append($('<div/>').addClass('net-ms').text(
                    probe.rtt === null ? (probe.loss === null ? '\u2014' : '{{ lang._("no answer") }}')
                                       : Math.round(probe.rtt) + ' ms'));
                if (probe.loss) {
                    $p.append($('<div/>').addClass('net-loss')
                        .text(Math.round(probe.loss) + '% {{ lang._("lost") }}'));
                }
                $p.append(probeSpark(probe.series));
                $probes.append($p);
            }
            if (!net.probing) {
                $probes.append($('<div/>').addClass('dash-sub').text(
                    '{{ lang._("Pinging is switched off under Services: Lens: Settings, so the state above comes from the gateway alone.") }}'));
            } else if (!net.probes.some(p => p.rtt !== null || p.loss !== null)) {
                $probes.append($('<div/>').addClass('dash-sub').text(
                    '{{ lang._("The first round of pings runs within five minutes of installing.") }}'));
            }

            const uptime = net.uptime;
            $('#netUptimePct').text(uptime.percent === null ? '\u2014' : uptime.percent + '%');
            $('#netLastOutage').text(uptime.last
                ? '{{ lang._("last outage") }} ' + uptime.last.ago + ', ' + uptime.last.for
                : (uptime.rounds ? '{{ lang._("no outage in this range") }}' : ''));

            const $strip = $('#netStrip').empty();
            for (const state of uptime.strip) {
                $strip.append($('<span/>').addClass('net-seg net-seg-' + state));
            }

            const $outages = $('#netOutages').empty();
            for (const outage of uptime.outages.slice(0, 3)) {
                $outages.append($('<div/>').text(
                    new Date(outage.from * 1000).toLocaleString() + ' \u2014 ' + outage.for));
            }
        });

        /* ------------------------------------------------ the line */
        const lineSpark = (series) => {
            const node = svg('svg', { viewBox: '0 0 300 50', preserveAspectRatio: 'none',
                                      class: 'line-spark' });
            const delays = series.map(p => p.delay).filter(v => v !== null);
            if (series.length < 2 || !delays.length) {
                return node;
            }
            const peak = Math.max(1, ...delays);
            const x = (i) => i / (series.length - 1) * 300;
            const y = (v) => 46 - v / peak * 40;

            /* loss as marks along the floor: rare, but the thing people noticed */
            series.forEach((p, i) => {
                if (p.loss) {
                    node.appendChild(svg('rect', { x: x(i) - 1.5, y: 44, width: 3, height: 6,
                                                   class: 'line-loss' }));
                }
            });

            let path = '';
            series.forEach((p, i) => {
                if (p.delay === null) {
                    return;
                }
                path += (path ? ' L ' : 'M ') + x(i) + ' ' + y(p.delay);
            });
            node.appendChild(svg('path', { d: path, class: 'line-path' }));
            return node;
        };

        ajaxGet('/api/lens/dashboard/line', { hours: hours }, (line, status) => {
            if (status !== 'success' || !line || !line.lines) {
                return;
            }
            const $box = $('#dashLine').empty();
            const shown = line.lines.filter(l => l.monitored || l.state === 'down');

            if (!shown.length) {
                $box.append($('<div/>').addClass('dash-sub').text(
                    '{{ lang._("No gateway is being monitored. Switch on monitoring under System: Gateways and dpinger starts measuring latency and loss.") }}'));
                return;
            }

            for (const gw of shown) {
                const fmt = (v, unit) => v === null ? '\u2014' : v.toFixed(1) + unit;
                const $row = $('<div/>').addClass('line-row line-' + gw.state);
                $row.append($('<div/>').addClass('line-head')
                    .append($('<span/>').addClass('line-dot'))
                    .append($('<b/>').text(gw.name))
                    .append($('<span/>').addClass('dash-sub').text(
                        ' ' + gw.status + (gw.monitor ? ' \u00b7 ' + gw.monitor : ''))));
                $row.append($('<div/>').addClass('line-figures')
                    .append($('<span/>').addClass('line-big').text(fmt(gw.delay, ' ms')))
                    .append($('<span/>').addClass('dash-sub').text(
                        '{{ lang._("jitter") }} ' + fmt(gw.stddev, ' ms') + ' \u00b7 {{ lang._("loss") }} '
                        + fmt(gw.loss, '%'))));
                $row.append(lineSpark(gw.series || []));
                $box.append($row);
            }
            if (line.sampling === false) {
                $box.append($('<div/>').addClass('dash-sub').text(
                    '{{ lang._("Gateway history is switched off under Services: Lens: Settings; the readings above are live.") }}'));
            }
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

        const readSystem = () => ajaxGet('/api/lens/dashboard/system', {}, (sys, status) => {
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
                if (lastWan && sys.wan.at > lastWan.at) {
                    const seconds = sys.wan.at - lastWan.at;
                    const down = Math.max(0, sys.wan.received - lastWan.received) * 8 / seconds;
                    const up = Math.max(0, sys.wan.sent - lastWan.sent) * 8 / seconds;
                    const bits = (b) => b >= 1e9 ? (b / 1e9).toFixed(1) + ' Gbit/s'
                        : b >= 1e6 ? (b / 1e6).toFixed(1) + ' Mbit/s'
                        : Math.round(b / 1e3) + ' kbit/s';
                    $('#netDown').text(bits(down));
                    $('#netUp').text(bits(up));
                } else {
                    $('#netDown').text('…');
                    $('#netUp').text('…');
                }
                lastWan = sys.wan;
            }
        });

        range();
        readSystem();
        setInterval(readSystem, 5000);
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

<div id="netPanel" class="content-box net-panel" style="display: none;">
    <div class="net-top">
        <div class="net-state">
            <span class="net-dot" id="netDot"></span>
            <span class="net-title">{{ lang._('Internet') }}</span>
            <span id="netState" class="net-state-text"></span>
        </div>
        <div class="net-addr">
            <span class="dash-sub" id="netWanName">WAN</span>
            <span class="net-ip" id="netV4"></span>
            <span class="net-ip net-v6" id="netV6"></span>
        </div>
    </div>

    <div class="net-mid">
        <div class="net-rate">
            <div><span class="net-arrow down">&#9660;</span><span class="net-big" id="netDown">&hellip;</span></div>
            <div><span class="net-arrow up">&#9650;</span><span class="net-big" id="netUp">&hellip;</span></div>
            <div class="dash-sub">{{ lang._('right now, on the WAN') }}</div>
        </div>
        <div class="net-probes" id="netProbes"></div>
    </div>

    <div class="net-uptime">
        <div class="net-uptime-head">
            <span>{{ lang._('Uptime') }} <b id="netUptimePct"></b></span>
            <span class="dash-sub" id="netLastOutage"></span>
        </div>
        <div class="net-strip" id="netStrip"></div>
        <div class="net-outages" id="netOutages"></div>
    </div>
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
        <div class="lens-plot">
            <svg id="dashChart" class="dash-chart" preserveAspectRatio="none"></svg>
            <div class="lens-plot-label" id="dashChartPeak"></div>
        </div>
        <div class="lens-axis-row" id="dashChartAxis"></div>
        <div class="dash-legend">
            <span class="lens-key received"></span>{{ lang._('received') }}
            <span class="lens-key sent"></span>{{ lang._('sent by your devices') }}
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
        <div id="dashNetworks"></div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title"><span>{{ lang._('The line') }}</span></div>
        <div id="dashLine"></div>
        <div class="lens-note-under">{{ lang._('Measured by dpinger, judged against your own gateway thresholds.') }}</div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title"><span>{{ lang._('When your network is busy') }}</span></div>
        <div class="hm-scroll"><div class="hm" id="dashHeatmap"></div></div>
        <div class="dash-sub" id="dashHeatmapNote" style="margin-top: 8px;"></div>
    </div>

    <div class="content-box dash-card">
        <div class="dash-title">
            <span>{{ lang._('This firewall') }}</span>
            <span class="dash-sub">{{ lang._('up') }} <span id="sysUptime"></span></span>
        </div>
        <div class="dash-meter" id="meterLoad" style="display:none;">
            <div class="dash-meter-label"><span>{{ lang._('Load') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-meter" id="meterMem" style="display:none;">
            <div class="dash-meter-label"><span>{{ lang._('Memory') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-meter" id="meterDisk" style="display:none;">
            <div class="dash-meter-label"><span>{{ lang._('Disk') }}</span><span class="value"></span></div>
            <div class="track"><div class="fill"></div></div>
        </div>
        <div class="dash-sub">{{ lang._('Computed exactly as OPNsense\'s own system widgets compute them.') }}</div>
    </div>
</div>
