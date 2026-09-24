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
    .dv-back { margin-bottom: 10px; display: inline-block; }
    .dv-hero { display: flex; gap: 20px; align-items: center; padding: 20px 22px;
               margin: 0 0 14px 0; }
    .dv-avatar { width: 72px; height: 72px; flex: 0 0 72px; border-radius: 50%;
                 display: flex; align-items: center; justify-content: center;
                 background: rgba(217, 79, 0, 0.15); color: #d94f00; font-size: 34px; }
    .dv-name { font-size: 24px; font-weight: 600; line-height: 1.15; }
    .dv-meta { color: #999; margin-top: 4px; }
    .dv-meta code { background: none; color: inherit; padding: 0; }
    .dv-pills { margin-top: 8px; }
    .dv-pill { display: inline-block; padding: 1px 9px; margin: 2px 4px 2px 0;
               border-radius: 10px; font-size: 12px; border: 1px solid #999; }
    .dv-pill.home { border-color: #5cb85c; color: #5cb85c; }
    .dv-pill.away { color: #999; }
    .dv-pill.warn { border-color: #f0ad4e; color: #f0ad4e; }
    .dv-hero-edit { margin-left: auto; align-self: flex-start; }

    .dv-facts { display: grid; gap: 14px; margin-bottom: 14px;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .dv-fact { padding: 12px 14px; margin: 0; }
    .dv-num { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .dv-sub { color: #999; font-size: 12px; }

    .dv-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); }
    .dv-card { padding: 14px 16px; margin: 0; }
    .dv-wide { grid-column: 1 / -1; }
    .dv-title { font-size: 11px; font-weight: 600; letter-spacing: 0.06em;
                text-transform: uppercase; color: #999; margin: 0 0 10px 0;
                display: flex; justify-content: space-between; align-items: center; }

    .dv-chart { width: 100%; height: 160px; display: block; }
    .dv-up { fill: #d94f00; }
    .dv-down { fill: #7a8b99; }
    .dv-empty { fill: #555; }

    .hm { display: grid; grid-template-columns: 34px repeat(24, 1fr); gap: 2px; }
    .hm-cell { aspect-ratio: 1 / 1; border-radius: 2px; min-height: 10px; }
    .hm-l0 { background: rgba(128, 128, 128, 0.12); }
    .hm-l1 { background: rgba(217, 79, 0, 0.25); }
    .hm-l2 { background: rgba(217, 79, 0, 0.5); }
    .hm-l3 { background: rgba(217, 79, 0, 0.75); }
    .hm-l4 { background: rgba(217, 79, 0, 1); }
    .hm-day, .hm-hour { font-size: 10px; color: #999; }
    .hm-day { align-self: center; }
    .hm-hour { text-align: center; }

    .dv-story { list-style: none; padding: 0; margin: 0; }
    .dv-story li { position: relative; padding: 0 0 12px 22px; }
    .dv-story li::before { content: ''; position: absolute; left: 5px; top: 5px;
                           width: 9px; height: 9px; border-radius: 50%; background: #999; }
    .dv-story li.current::before { background: #5cb85c; }
    .dv-story li::after { content: ''; position: absolute; left: 9px; top: 16px; bottom: 0;
                          width: 1px; background: rgba(128, 128, 128, 0.35); }
    .dv-story li:last-child::after { display: none; }
    .dv-story .addr { font-weight: 600; }
    .dv-story .when { color: #999; font-size: 12px; }

    .dv-strip { position: relative; height: 18px; border-radius: 3px;
                background: rgba(128, 128, 128, 0.12); }
    .dv-span { position: absolute; top: 0; height: 18px; border-radius: 3px;
               background: #d94f00; opacity: 0.85; min-width: 2px; }

    .lens-chip { display: inline-block; padding: 1px 8px; margin: 0 2px;
                 border: 1px solid #999; border-radius: 10px; font-size: 90%;
                 text-decoration: none; cursor: pointer; }
    .lens-chip-on { border-color: #d94f00; color: #d94f00; font-weight: 600; }
</style>

<script>
    $(document).ready(() => {
        const mac = (new URLSearchParams(location.search).get('mac') || '').toLowerCase();
        const LABELS = { 24: '{{ lang._("24 hours") }}', 168: '{{ lang._("7 days") }}',
                         720: '{{ lang._("30 days") }}' };
        let hours = 24;
        let kinds = {};
        let device = null;

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

        /* ------------------------------------------------ the traffic chart */
        const chart = () => {
            $('#dvRange .lens-chip').each(function () {
                $(this).toggleClass('lens-chip-on', parseInt($(this).data('hours'), 10) === hours);
            });

            ajaxGet('/api/lens/devices/history', { mac: mac, hours: hours }, (detail, status) => {
                const node = document.getElementById('dvChart');
                while (node.firstChild) {
                    node.removeChild(node.firstChild);
                }
                if (status !== 'success' || !detail || !detail.series) {
                    return;
                }

                const width = 800;
                const height = 160;
                const points = detail.series;
                const step = width / Math.max(1, points.length);
                const gap = points.length > 60 ? 0 : 1;
                const peak = detail.peak || 1;
                node.setAttribute('viewBox', '0 0 ' + width + ' ' + height);

                points.forEach((point, index) => {
                    const x = index * step;
                    const w = Math.max(0.5, step - gap);
                    const when = new Date(point.bucket * 1000).toLocaleString();
                    if (!point.total) {
                        node.appendChild(svg('rect', { x: x, y: height - 1, width: w, height: 1,
                                                       class: 'dv-empty' }));
                        return;
                    }
                    const down = point.received / peak * height;
                    const up = point.sent / peak * height;
                    for (const [y, h, cls] of [[height - down - up, down, 'dv-down'],
                                                [height - up, up, 'dv-up']]) {
                        const rect = svg('rect', { x: x, y: y, width: w, height: Math.max(0, h),
                                                   class: cls, style: 'cursor: pointer' });
                        rect.addEventListener('click', () => moment(detail, point));
                        const title = svg('title');
                        title.textContent = when + ' — ' + bytes(point.sent) + ' up, '
                            + bytes(point.received) + ' down';
                        rect.appendChild(title);
                        node.appendChild(rect);
                    }
                });

                $('#dvChartTotal').text(detail.total + ' · ' + detail.sent + ' up · '
                                        + detail.received + ' down');
                $('#dvChartBusy').text(detail.busiest
                    ? '{{ lang._("busiest") }} ' + detail.busiest.what + ' · '
                      + new Date(detail.busiest.bucket * 1000).toLocaleString()
                    : '');
            });
        };

        /* a bar opens the addresses behind it; the rows add up to the bar (§4.44) */
        const moment = (detail, point) => {
            $('#dvMomentWhen').text(new Date(point.bucket * 1000).toLocaleString());
            const $rows = $('#dvMomentRows').empty();
            $('#dvMoment').show();
            ajaxGet('/api/lens/devices/moment', { mac: mac, at: point.bucket, step: detail.step },
                    (data, status) => {
                if (status !== 'success' || !data || !data.addresses) {
                    return;
                }
                for (const row of data.addresses) {
                    $rows.append($('<tr/>')
                        .append($('<td/>').text(row.address))
                        .append($('<td/>').addClass('dv-sub').text(row.interface))
                        .append($('<td/>').text(row.traffic + ' (' + row.sent + ' \u2191, '
                                                + row.received + ' \u2193)')));
                }
            });
        };

        /* ------------------------------------------------ the week */
        const heatmap = (grid) => {
            const $hm = $('#dvHeatmap').empty();
            $hm.append($('<div/>'));
            for (let hour = 0; hour < 24; hour++) {
                $hm.append($('<div/>').addClass('hm-hour').text(hour % 3 === 0 ? hour : ''));
            }
            grid.rows.forEach((row, day) => {
                $hm.append($('<div/>').addClass('hm-day').text(grid.days[day]));
                row.forEach((cell, hour) => {
                    $hm.append($('<div/>').addClass('hm-cell hm-l' + cell.level)
                        .attr('title', grid.days[day] + ' ' + hour + ':00 — ' + cell.text));
                });
            });
            $('#dvHeatmapNote').text(grid.empty
                ? '{{ lang._("Nothing measured in the last four weeks.") }}'
                : '{{ lang._("Four weeks folded onto one, local time. Darkest:") }} ' + grid.peak
                  + (grid.busiest ? ' · ' + grid.days[grid.busiest.day] + ' '
                                    + grid.busiest.hour + ':00' : ''));
        };

        /* ------------------------------------------------ this week's presence */
        const presence = () => ajaxGet('/api/lens/devices/presence', { hours: 168 }, (report, status) => {
            if (status !== 'success' || !report) {
                return;
            }
            const all = (report.moving || []).concat(report.always || []);
            const mine = all.find(entry => entry.mac === mac);
            const $strip = $('#dvStrip').empty();
            const span = Math.max(1, report.now - report.start);

            if (!mine) {
                $('#dvStripNote').text('{{ lang._("Not seen in the last seven days.") }}');
                return;
            }
            for (const [from, to] of mine.spans) {
                $('<div/>').addClass('dv-span').css({
                    left: ((from - report.start) / span * 100) + '%',
                    width: ((to - from) / span * 100) + '%'
                }).attr('title', new Date(from * 1000).toLocaleString() + ' – '
                                 + new Date(to * 1000).toLocaleString()).appendTo($strip);
            }
            $('#dvStripNote').text('{{ lang._("Present for") }} ' + mine.present
                                   + ' {{ lang._("of the last seven days") }} (' + mine.coverage + '%)');
        });

        /* ------------------------------------------------ the page */
        const render = (profile) => {
            device = profile.device;
            kinds = profile.kinds || {};
            document.title = device.name + ' | Lens';

            $('#dvIcon').attr('class', 'fa ' + device.kind.icon);
            $('#dvName').text(device.name);
            $('#dvMeta').empty()
                .append(document.createTextNode((device.vendor || '{{ lang._("unknown vendor") }}') + ' · '))
                .append($('<code/>').text(device.mac))
                .append(document.createTextNode(' · ' + device.kind.type));

            const $pills = $('#dvPills').empty();
            $pills.append($('<span/>').addClass('dv-pill ' + (device.here ? 'home' : 'away'))
                .text(device.here ? '{{ lang._("home now") }}' : device.presence));
            if (device.role) {
                $pills.append($('<span/>').addClass('dv-pill').text(device.role));
            }
            if (device.randomised) {
                $pills.append($('<span/>').addClass('dv-pill warn').attr('title', device.caveat)
                    .text('{{ lang._("randomised MAC") }}'));
            }
            for (const tag of device.tags) {
                $pills.append($('<span/>').addClass('dv-pill').text(tag));
            }
            if (device.label.note) {
                $pills.append($('<div/>').addClass('dv-sub').text(device.label.note));
            }

            $('#dvFactTraffic').text(device.traffic ? device.traffic.split('  ')[0] : '0 B');
            $('#dvFactKnown').text(profile.facts.known_for || '');
            $('#dvFactFirst').text(profile.facts.first_seen || '');
            $('#dvFactVisits').text(profile.facts.visits);
            $('#dvFactSegments').text(profile.facts.segments);
            $('#dvFactNamed').text(device.named_short);
            $('#dvFactNamedSub').text(device.named_by);

            heatmap(profile.heatmap);

            const $story = $('#dvStory').empty();
            for (const entry of profile.story) {
                $story.append($('<li/>').toggleClass('current', entry.current)
                    .append($('<span/>').addClass('addr').text(entry.address))
                    .append(document.createTextNode(' · ' + entry.interface))
                    .append($('<div/>').addClass('when').text(
                        entry.from_text + ' → ' + entry.to_text
                        + (entry.visits > 1 ? ' · ' + entry.visits + ' {{ lang._("visits") }}' : ''))));
            }

            $('#dvLoading').hide();
            $('#dvPage').show();
        };

        const load = () => ajaxGet('/api/lens/devices/profile', { mac: mac }, (profile, status) => {
            if (status !== 'success' || !profile || profile.status !== 'ok') {
                $('#dvLoading').hide();
                $('#dvError').text((profile && profile.message)
                    || '{{ lang._("This device could not be read.") }}').show();
                return;
            }
            render(profile);
        });

        /* ------------------------------------------------ editing, in place */
        $('#dvEdit').on('click', (event) => {
            event.preventDefault();
            $('#dvEditName').val(device.label.name);
            $('#dvEditTags').val(device.label.tags);
            $('#dvEditNote').val(device.label.note);
            const $kind = $('#dvEditKind').empty();
            $('<option/>').val('').text('{{ lang._("work it out from the vendor") }} — '
                                         + device.kind.type).appendTo($kind);
            for (const key of Object.keys(kinds)) {
                $('<option/>').val(key).text(kinds[key]).appendTo($kind);
            }
            $kind.val(device.label.kind || '');
            $('#dvEditBox').slideDown(120);
        });
        $('#dvEditCancel').on('click', (event) => {
            event.preventDefault();
            $('#dvEditBox').slideUp(120);
        });
        $('#dvEditSave').on('click', () => {
            ajaxCall('/api/lens/devices/label', {
                mac: device.mac, name: $('#dvEditName').val(), kind: $('#dvEditKind').val(),
                tags: $('#dvEditTags').val(), note: $('#dvEditNote').val()
            }, (reply, status) => {
                if (status !== 'success' || !reply || reply.status !== 'ok') {
                    $('#dvEditError').text((reply && reply.message)
                        || '{{ lang._("The name was not saved.") }}').show();
                    return;
                }
                $('#dvEditBox').slideUp(120);
                load();
            });
        });

        $('#dvRange').on('click', '.lens-chip', function (event) {
            event.preventDefault();
            hours = parseInt($(this).data('hours'), 10);
            chart();
        });

        if (!mac) {
            $('#dvLoading').hide();
            $('#dvError').text('{{ lang._("No device was named in the address.") }}').show();
            return;
        }

        load();
        chart();
        presence();
    });
</script>

<a class="dv-back" href="/ui/lens/overview">&lsaquo; {{ lang._('All devices') }}</a>

<div id="dvLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading this device...') }}</div>
<div id="dvError" class="alert alert-danger" style="display: none;"></div>

<div id="dvPage" style="display: none;">
    <div class="content-box dv-hero">
        <div class="dv-avatar"><i id="dvIcon"></i></div>
        <div>
            <div class="dv-name" id="dvName"></div>
            <div class="dv-meta" id="dvMeta"></div>
            <div class="dv-pills" id="dvPills"></div>
        </div>
        <a href="#" id="dvEdit" class="btn btn-default btn-sm dv-hero-edit">
            <i class="fa fa-pencil"></i> {{ lang._('Name it') }}
        </a>
    </div>

    <div id="dvEditBox" class="content-box dv-card" style="display: none; margin-bottom: 14px;">
        <div id="dvEditError" class="alert alert-danger" style="display: none;"></div>
        <div class="row">
            <div class="col-md-3"><label>{{ lang._('Name') }}</label>
                <input type="text" id="dvEditName" class="form-control"
                       placeholder="{{ lang._('keep the observed name') }}"></div>
            <div class="col-md-3"><label>{{ lang._('Kind') }}</label>
                <select id="dvEditKind" class="form-control"></select></div>
            <div class="col-md-3"><label>{{ lang._('Tags') }}</label>
                <input type="text" id="dvEditTags" class="form-control"
                       placeholder="{{ lang._('comma separated') }}"></div>
            <div class="col-md-3"><label>{{ lang._('Note') }}</label>
                <input type="text" id="dvEditNote" class="form-control"></div>
        </div>
        <div style="margin-top: 10px;">
            <button id="dvEditSave" class="btn btn-primary btn-sm">{{ lang._('Save') }}</button>
            <a href="#" id="dvEditCancel" style="margin-left: 10px;">{{ lang._('Cancel') }}</a>
        </div>
    </div>

    <div class="dv-facts">
        <div class="content-box dv-fact"><div class="dv-num" id="dvFactTraffic"></div>
            <div class="dv-sub">{{ lang._('in the last 24 hours') }}</div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dvFactKnown"></div>
            <div class="dv-sub">{{ lang._('known since') }} <span id="dvFactFirst"></span></div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dvFactVisits"></div>
            <div class="dv-sub">{{ lang._('visits to the network') }}</div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dvFactSegments"></div>
            <div class="dv-sub">{{ lang._('segments it has used') }}</div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dvFactNamed"></div>
            <div class="dv-sub" id="dvFactNamedSub"></div></div>
    </div>

    <div class="dv-grid">
        <div class="content-box dv-card dv-wide">
            <div class="dv-title">
                <span>{{ lang._('Traffic') }} &middot; <span id="dvChartTotal" style="text-transform:none;letter-spacing:0;"></span></span>
                <span id="dvRange">
                    <a class="lens-chip" data-hours="24">{{ lang._('24 hours') }}</a>
                    <a class="lens-chip" data-hours="168">{{ lang._('7 days') }}</a>
                    <a class="lens-chip" data-hours="720">{{ lang._('30 days') }}</a>
                </span>
            </div>
            <svg id="dvChart" class="dv-chart" preserveAspectRatio="none"></svg>
            <div class="dv-sub" id="dvChartBusy"></div>
            <div id="dvMoment" style="display: none; margin-top: 10px;">
                <b>{{ lang._('That slice was') }}</b> &middot; <span id="dvMomentWhen" class="dv-sub"></span>
                <table class="table table-condensed"><tbody id="dvMomentRows"></tbody></table>
            </div>
        </div>

        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Its week') }}</span></div>
            <div class="hm" id="dvHeatmap"></div>
            <div class="dv-sub" id="dvHeatmapNote" style="margin-top: 8px;"></div>
        </div>

        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Where it has been') }}</span></div>
            <ul class="dv-story" id="dvStory"></ul>
        </div>

        <div class="content-box dv-card dv-wide">
            <div class="dv-title"><span>{{ lang._('Home in the last seven days') }}</span></div>
            <div class="dv-strip" id="dvStrip"></div>
            <div class="dv-sub" id="dvStripNote" style="margin-top: 6px;"></div>
        </div>
    </div>
</div>
