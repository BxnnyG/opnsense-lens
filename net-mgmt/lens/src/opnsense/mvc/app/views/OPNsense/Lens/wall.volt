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
        /*
         * Everything here comes from one endpoint that composes what the pages
         * already say (§4.37, §4.71). A wall display that disagrees with the
         * page is the worst of the three, because nobody is standing at it to
         * notice. This script only lays it out.
         */
        const REFRESH = 60000;
        const ICONS = {
            new: 'fa-plus-circle', unusual: 'fa-line-chart', outage: 'fa-plug',
            gateway: 'fa-exchange', rotated: 'fa-random', overlap: 'fa-clone'
        };

        const svg = (tag, attrs) => {
            const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
            for (const [key, value] of Object.entries(attrs || {})) {
                node.setAttribute(key, value);
            }
            return node;
        };

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

        /* the clock runs on its own second, the data on its minute */
        const clock = () => {
            const now = new Date();
            $('#wallClock').text(now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
            $('#wallDate').text(now.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' }));
        };

        const figures = (data) => {
            const f = data.figures;
            $('#wallHere').text(f.here);
            $('#wallKnown').text('/ ' + f.known);
            $('#wallMoved').text(f.moved || '0 B');
            $('#wallChange').attr('class', 'wall-change wall-' + (f.change ? f.change.direction : 'none'))
                .text(f.change && f.change.text ? f.change.text + ' ' + f.change_label : '');
            if (f.new === null) {
                $('#wallNew').text(f.watching_for);
                $('#wallNewLabel').text('{{ lang._("watching so far") }}');
            } else {
                $('#wallNew').text(f.new);
                $('#wallNewLabel').text('{{ lang._("new in 24 hours") }}');
            }
            $('#wallEvents').text(f.events);

            const sentence = data.sentence || {};
            $('#wallSentence').attr('class', 'wall-sentence wall-' + (sentence.tone || 'calm'))
                .text(sentence.sentence || '');
        };

        const line = (internet) => {
            const state = internet.state || {};
            $('#wallLine').attr('class', 'wall-line wall-line-' + (state.key || 'unknown'));
            $('#wallLineText').text(state.text || '');
            const $probes = $('#wallProbes').empty();
            for (const probe of internet.probes || []) {
                $probes.append($('<span/>').addClass('wall-probe')
                    .append($('<b/>').text(probe.rtt === null ? '–' : Math.round(probe.rtt) + ' ms'))
                    .append(document.createTextNode(' ' + probe.target)));
            }
            $('#wallUptime').text(internet.uptime === null || internet.uptime === undefined
                ? '' : internet.uptime + ' % {{ lang._("up, 24 hours") }}');
        };

        /* the network's day: received and sent stacked, as on the dashboard */
        const chart = (timeline) => {
            const chart = document.getElementById('wallChart');
            const width = 800;
            const height = 200;
            const pad = 12;
            const points = timeline.series || [];
            const peak = timeline.peak || 1;
            while (chart.firstChild) {
                chart.removeChild(chart.firstChild);
            }
            chart.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
            $('#wallChartPeak').text(timeline.peak_text ? timeline.peak_text + ' {{ lang._("peak per hour") }}' : '');
            if (!points.length) {
                return;
            }

            const x = (i) => points.length === 1 ? width / 2 : i / (points.length - 1) * width;
            const y = (v) => height - 1 - (v / peak) * (height - 1 - pad);
            const area = (top, bottom) => {
                let path = 'M ' + x(0) + ' ' + y(bottom(points[0]));
                points.forEach((p, i) => { path += ' L ' + x(i) + ' ' + y(top(p)); });
                for (let i = points.length - 1; i >= 0; i--) {
                    path += ' L ' + x(i) + ' ' + y(bottom(points[i]));
                }
                return path + ' Z';
            };
            const trace = (top) => points.map((p, i) => (i ? 'L ' : 'M ') + x(i) + ' ' + y(top(p))).join(' ');

            chart.appendChild(svg('line', { x1: 0, x2: width, y1: y(0), y2: y(0), class: 'lens-gridline' }));
            chart.appendChild(svg('line', { x1: 0, x2: width, y1: y(peak), y2: y(peak), class: 'lens-gridline' }));
            chart.appendChild(svg('path', { d: area(p => p.sent + p.received, p => p.sent), class: 'dash-down' }));
            chart.appendChild(svg('path', { d: area(p => p.sent, () => 0), class: 'dash-up' }));
            chart.appendChild(svg('path', { d: trace(p => p.sent + p.received), class: 'lens-received-line' }));
            chart.appendChild(svg('path', { d: trace(p => p.sent), class: 'lens-sent-line' }));
            Lens.axis(document.getElementById('wallChartAxis'), points,
                      (i) => points.length === 1 ? 0.5 : i / (points.length - 1), timeline.step);

            /* nothing on a wall is pointed at, but the same board open on a desk is */
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
                    { label: Lens.when(p.at, timeline.step, true) },
                    { value: bytes(p.received), label: '{{ lang._("received") }}', key: 'var(--lens-received)' },
                    { value: bytes(p.sent), label: '{{ lang._("sent by your devices") }}', key: 'var(--lens-sent)' },
                ];
            });
            hit.addEventListener('pointerleave', () => cross.setAttribute('visibility', 'hidden'));
        };

        /* how many rows fit, rather than a number picked at the desk */
        const top = (rows) => {
            const $rows = $('#wallRows').empty();
            const room = Math.floor(($('#wallRowsBox').height() || 240) / 38);
            const shown = rows.slice(0, Math.max(3, Math.min(rows.length, room)));
            const largest = shown.length ? shown[0].octets : 0;
            for (const entry of shown) {
                $rows.append($('<div/>').addClass('wall-row')
                    .append($('<div/>').addClass('wall-name').attr('title', entry.name)
                        .append($('<i/>').addClass('fa fa-fw ' + entry.icon))
                        .append(document.createTextNode(' ' + entry.name)))
                    .append($('<div/>').addClass('wall-track').append($('<div/>').addClass('wall-fill')
                        .css('width', largest ? Math.max(1, entry.octets / largest * 100) + '%' : 0)))
                    .append($('<div/>').addClass('wall-bytes').text(entry.text)));
            }
            if (!shown.length) {
                $rows.append($('<div/>').addClass('wall-quiet')
                    .text('{{ lang._("No device has attributed traffic in the last 24 hours yet.") }}'));
            }
        };

        const when = (event) => {
            if (event.grain === 'moment') {
                return event.ago;
            }
            const day = new Date(event.at * 1000);
            return day.toDateString() === new Date().toDateString()
                ? '{{ lang._("today") }}'
                : day.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'short' });
        };

        const recent = (events) => {
            const $list = $('#wallRecent').empty();
            for (const event of events) {
                $list.append($('<li/>').addClass('wall-ev wall-ev-' + event.tone)
                    .append($('<i/>').addClass('fa fa-fw ' + (ICONS[event.kind] || 'fa-circle-o')))
                    .append($('<div/>').addClass('wall-ev-body')
                        .append($('<div/>').addClass('wall-ev-title').text(event.title))
                        .append($('<div/>').addClass('wall-ev-meta')
                            .text(event.word + ' · ' + when(event)))));
            }
            if (!events.length) {
                $list.append($('<li/>').addClass('wall-quiet')
                    .text('{{ lang._("Nothing happened this week that Lens would call news.") }}'));
            }
            /* fade only what runs out of room; a short list ends where it ends */
            $list.toggleClass('wall-more', $list[0].scrollHeight > $list[0].clientHeight + 2);
        };

        const people = (list) => {
            $('#wallPeopleBox').toggle(list.length > 0);
            const $people = $('#wallPeople').empty();
            for (const person of list) {
                $people.append($('<span/>').addClass('wall-person' + (person.here ? ' here' : ''))
                    .append($('<i/>').addClass('fa fa-fw ' + (person.here ? 'fa-home' : 'fa-circle-o')))
                    .append(document.createTextNode(' ' + person.name)));
            }
        };

        /* the room changes when the screen is filled: lay out again, don't ask again */
        let last = null;
        const fit = () => {
            if (last !== null) {
                top(last.top || []);
                recent(last.events || []);
            }
        };
        $(window).on('resize', fit);
        document.addEventListener('fullscreenchange', () => setTimeout(fit, 100));

        const load = () => ajaxGet('/api/lens/dashboard/wall', {}, (data, status) => {
            if (status !== 'success' || !data || !data.figures) {
                $('#wallStale').addClass('wall-warn')
                    .text('{{ lang._("Lens did not answer. This screen is not current.") }}');
                return;
            }
            $('#lensWall').addClass('wall-on');
            last = data;
            figures(data);
            line(data.internet || {});
            chart(data.timeline || {});
            top(data.top || []);
            recent(data.events || []);
            people(data.people || []);

            /* the one thing a wall display must never do is look current while
               being hours old -- nobody is there to wonder (§4.22) */
            $('#wallStale').toggleClass('wall-warn', !!data.stale).text(data.stale ? data.note : '');
            $('#wallAt').text(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        });

        $('#wallFull').on('click', (event) => {
            event.preventDefault();
            const board = document.getElementById('lensWallBoard');
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (board.requestFullscreen) {
                /* a full-screen element keeps no page behind it: without the
                   page's own surface, dark text lands on the black backdrop */
                const surface = document.querySelector('.page-content-main') || document.body;
                board.style.background = getComputedStyle(surface).backgroundColor;
                board.requestFullscreen();
            }
        });

        clock();
        setInterval(clock, 1000);
        load();
        setInterval(load, REFRESH);
    });
</script>

<div id="lensWallBoard">
    <p class="text-muted wall-intro">
        <a href="#" id="wallFull">{{ lang._('Fill the screen') }}</a>
        &mdash; {{ lang._('refreshes itself every minute; nothing here is clickable on purpose.') }}
    </p>

    <div id="lensWall">
        <div class="wall-top">
            <div class="wall-time">
                <div id="wallClock" class="wall-clock"></div>
                <div id="wallDate" class="wall-date"></div>
            </div>
            <div class="wall-figures">
                <div class="wall-figure">
                    <div class="wall-big"><span id="wallHere"></span><span id="wallKnown" class="wall-of"></span></div>
                    <div class="wall-label">{{ lang._('devices here now') }}</div>
                </div>
                <div class="wall-figure">
                    <div class="wall-big" id="wallMoved"></div>
                    <div class="wall-label">{{ lang._('moved in 24 hours') }}</div>
                    <div id="wallChange" class="wall-change"></div>
                </div>
                <div class="wall-figure">
                    <div class="wall-big" id="wallNew"></div>
                    <div class="wall-label" id="wallNewLabel"></div>
                </div>
                <div class="wall-figure">
                    <div class="wall-big" id="wallEvents"></div>
                    <div class="wall-label">{{ lang._('events in 24 hours') }}</div>
                </div>
            </div>
        </div>

        <div id="wallSentence" class="wall-sentence"></div>

        <div class="wall-main">
            <section class="wall-panel wall-live">
                <div class="wall-panel-head">
                    <span class="wall-title">{{ lang._('Live') }}</span>
                    <span id="wallLine" class="wall-line"><span class="wall-dot"></span> <span id="wallLineText"></span></span>
                    <span id="wallProbes" class="wall-probes"></span>
                    <span id="wallUptime" class="wall-uptime"></span>
                </div>
                <div class="wall-chart-head">
                    <span><span class="lens-key received"></span>{{ lang._('received') }}<span class="lens-key sent"></span>{{ lang._('sent by your devices') }}</span>
                    <span id="wallChartPeak"></span>
                </div>
                <svg id="wallChart" class="wall-chart" preserveAspectRatio="none" role="img"
                     aria-label="{{ lang._('The network over the last 24 hours') }}"></svg>
                <div id="wallChartAxis" class="lens-axis-row"></div>
                <div class="wall-sub">{{ lang._('Heaviest devices, 24 hours') }}</div>
                <div id="wallRowsBox" class="wall-rows-box"><div id="wallRows"></div></div>
            </section>

            <section class="wall-panel wall-side">
                <div class="wall-panel-head"><span class="wall-title">{{ lang._('Just now') }}</span></div>
                <ul id="wallRecent" class="wall-recent"></ul>
                <div id="wallPeopleBox" class="wall-people-box">
                    <div class="wall-sub">{{ lang._("Who's home") }}</div>
                    <div id="wallPeople" class="wall-people"></div>
                </div>
            </section>
        </div>

        <div class="wall-foot">
            <span id="wallStale"></span>
            <span class="pull-right">{{ lang._('updated') }} <span id="wallAt"></span></span>
        </div>
    </div>
</div>
