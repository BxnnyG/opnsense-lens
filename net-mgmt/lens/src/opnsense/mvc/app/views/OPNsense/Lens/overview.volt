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

<p>
    {{ lang._('Every device Lens has seen, and which addresses it held. OPNsense knows this only in the present tense; Lens keeps it.') }}
    <a href="/ui/lens/preflight">{{ lang._('Data sources are configured under Services: Lens.') }}</a>
</p>

<style>
    .lens-verdict { font-weight: 600; white-space: nowrap; }
    .lens-ready { color: #5cb85c; }
    .lens-degraded { color: #f0ad4e; }
    .lens-absent { color: #999; }
    .lens-cause { max-width: 40em; }
    .lens-action { display: block; margin-top: 4px; }
    .lens-block { margin-top: 25px; }
    #lensTiming { margin-top: 20px; }
    .lens-here { color: #5cb85c; font-weight: 600; }
    .lens-mac { font-family: monospace; font-size: 90%; color: #999; }
    .lens-addr { display: block; }
    .lens-addr-gone { color: #999; }
    .lens-if { color: #999; }
    .lens-caveat { display: block; color: #f0ad4e; margin-top: 3px; }
    .lens-role { display: block; font-size: 90%; color: #999; font-style: italic; }
    .lens-traffic { white-space: nowrap; min-width: 14em; }
    .lens-icon { margin-right: 6px; opacity: 0.75; }
    .lens-name { font-weight: 600; }
    .lens-mac { display: block; margin-left: 20px; }
    .lens-role { margin-left: 20px; }
    .lens-bar {
        display: block; height: 4px; margin-bottom: 3px;
        background: #d94f00; opacity: 0.55; min-width: 2px; border-radius: 2px;
    }
    .lens-bytes { font-size: 95%; }
    .lens-addr-toggle { display: block; font-size: 90%; }
    #lensControls { margin: 10px 0 14px 0; }
    #lensSearch { width: 22em; max-width: 100%; }
    #lensSegments { display: inline-block; margin-left: 6px; }
    .lens-chip {
        display: inline-block; padding: 1px 8px; margin: 2px 3px;
        border: 1px solid #999; border-radius: 10px; font-size: 90%;
        text-decoration: none;
    }
    .lens-chip-on { border-color: #d94f00; color: #d94f00; font-weight: 600; }
    #lensShowing { margin-left: 8px; color: #999; }
    .lens-edit { margin-left: 6px; opacity: 0.35; }
    tr:hover .lens-edit { opacity: 1; }
    .lens-tag {
        display: inline-block; margin: 3px 4px 0 20px;
        padding: 0 6px; border: 1px solid #999; border-radius: 8px; font-size: 85%;
    }
    .lens-note { display: block; margin-left: 20px; font-size: 90%; color: #999; }
    .lens-chart { width: 100%; height: 140px; display: block; }
    .lens-chart-up { fill: #d94f00; }
    .lens-chart-down { fill: #7a8b99; }
    .lens-chart-empty { fill: #666; }
    .lens-chart-legend { margin-top: 6px; }
    .lens-key {
        display: inline-block; width: 10px; height: 10px;
        margin: 0 2px 0 8px; border-radius: 2px;
    }
    .lens-key.lens-chart-up { background: #d94f00; }
    .lens-key.lens-chart-down { background: #7a8b99; }
    a.lens-name { color: inherit; }
    tr.lens-group > td { background: rgba(128, 128, 128, 0.08); }
    #lensRangeWrap { margin-top: 10px; }
    #lensRangeNote { margin-left: 10px; }
    #lensSummary { display: flex; flex-wrap: wrap; gap: 26px; margin: 14px 0 4px 0; }
    .lens-stat { min-width: 9em; }
    .lens-figure { font-size: 22px; font-weight: 600; line-height: 1.1; }
    .lens-caption { color: #999; font-size: 90%; max-width: 22em; }
</style>

<script>
    $(document).ready(() => {
        /*
         * Layout only. What a source IS -- ready, degraded, absent -- is decided
         * once in PHP (SourceReport), so that the next surface to show a source
         * cannot decide it differently.
         */
        const WORDS = {
            ready:    '{{ lang._("ready") }}',
            degraded: '{{ lang._("needs attention") }}',
            absent:   '{{ lang._("unavailable") }}'
        };

        const cell = (value) => $('<td/>').text(value === undefined || value === null ? '' : value);

        const verdictCell = (verdict) => $('<td/>').append(
            $('<span/>').addClass('lens-verdict lens-' + verdict).text(WORDS[verdict] || verdict)
        );

        const causeCell = (source) => {
            const $td = $('<td/>').addClass('lens-cause').text(source.cause || '');
            if (source.action) {
                $td.append($('<em/>').addClass('lens-action').text(source.action));
            }
            return $td;
        };

        const list = (names) => names && names.length ? names.join(', ') : '{{ lang._("none") }}';

        /*
         * Devices first: this is the answer the page exists to give. It is
         * loaded on its own request so that a slow source probe cannot delay it,
         * and so that one failing does not blank the other.
         */
        /*
         * Devices first: this is the answer the page exists to give. Loaded on
         * its own request so a slow source probe cannot delay it, and so one
         * failing does not blank the other.
         *
         * The report arrives once and is filtered in the browser. Fifty devices
         * is not a dataset -- it is a list a person is trying to find one thing
         * in, and a round trip per keystroke would make that worse, not better.
         */
        /*
         * The chosen range lives in the query string rather than in a variable:
         * it survives a reload, it can be linked to, and the back button does
         * what a person expects. `Window` in PHP owns which ranges exist, so
         * this cannot offer one the API would then refuse.
         */
        const LABELS = { 24: '{{ lang._("24 hours") }}',
                         168: '{{ lang._("7 days") }}',
                         720: '{{ lang._("30 days") }}' };

        const chosenHours = () => {
            const asked = parseInt(new URLSearchParams(location.search).get('hours'), 10);
            return LABELS[asked] ? asked : 24;
        };

        const drawRange = (window_) => {
            const $bar = $('#lensRange').empty();
            for (const hours of (window_.choices || [])) {
                const url = location.pathname + '?hours=' + hours;
                $('<a/>').addClass('lens-chip')
                    .toggleClass('lens-chip-on', hours === chosenHours())
                    .attr('href', url)
                    .text(LABELS[hours] || hours + ' h')
                    .appendTo($bar);
            }

            /* asking for thirty days on a box that has eleven is not an error;
               showing eleven under a heading that says thirty would be */
            $('#lensRangeNote').toggle(!!window_.note).text(window_.note || '');
            $bar.show();
        };

        let devices = [];

        /* arriving from the Networks page with one segment already chosen */
        const asked = new URLSearchParams(location.search).get('segment');
        let segments = new Set(asked ? [asked] : []);
        let kinds = {};
        let groups = [];
        let editing = null;
        let opened = new Set();

        const render = () => {
            const needle = ($('#lensSearch').val() || '').toLowerCase().trim();
            const onlyBusy = $('#lensOnlyTraffic').is(':checked');

            const shown = devices.filter(device => {
                if (needle && device.haystack.indexOf(needle) === -1) {
                    return false;
                }
                if (onlyBusy && !device.octets) {
                    return false;
                }
                if (segments.size && !device.interfaces.some(i => segments.has(i))) {
                    return false;
                }
                return true;
            });

            /* the bar is relative to what is on screen, so filtering to one
               segment rescales it instead of leaving every bar a sliver */
            let largest = shown.reduce((max, d) => Math.max(max, d.octets), 0);

            /*
             * Grouping is off while a search is running. Search exists to find
             * one device; a group that hides the match would undo the thing the
             * box is for. Filtering by segment is different -- it narrows, it
             * does not look for something -- so groups survive it.
             */
            const grouping = $('#lensGroup').is(':checked') && !needle;
            const collapsed = new Map();
            if (grouping) {
                for (const group of groups) {
                    if (!opened.has(group.key)) {
                        collapsed.set(group.key, group);
                    }
                }
            }

            for (const group of collapsed.values()) {
                const total = shown
                    .filter(device => device.group === group.key)
                    .reduce((sum, device) => sum + device.octets, 0);
                largest = Math.max(largest, total);
            }

            const $body = $('#lensDevices > tbody').empty();
            const drawn = new Set();

            for (const device of shown) {
                const group = collapsed.get(device.group);
                if (!group) {
                    $body.append(deviceRow(device, largest));
                    continue;
                }
                if (!drawn.has(group.key)) {
                    drawn.add(group.key);
                    $body.append(groupRow(group, largest, shown));
                }
            }

            $('#lensShowing').text(
                shown.length === devices.length
                    ? ''
                    : shown.length + ' {{ lang._("of") }} ' + devices.length
            );
            $('#lensEmpty').toggle(shown.length === 0 && devices.length > 0);
        };

        const groupRow = (group, largest, shown) => {
            /* what is in this group *after* filtering, not what the report
               counted: a segment filter must not leave a group claiming
               members that are no longer on screen */
            const members = shown.filter(device => device.group === group.key);
            const octets = members.reduce((sum, device) => sum + device.octets, 0);
            const here = members.filter(device => device.here).length;

            const $name = $('<td/>');
            $name.append($('<i/>').addClass('fa fa-fw lens-icon fa-caret-right'));
            $name.append($('<a/>').addClass('lens-name').attr('href', '#')
                .text(group.label)
                .on('click', function (event) {
                    event.preventDefault();
                    opened.add(group.key);
                    render();
                }));
            $name.append($('<span/>').addClass('lens-mac').text(
                members.length + ' {{ lang._("devices, grouped by") }} ' + group.by
            ));

            const $traffic = $('<td/>').addClass('lens-traffic');
            if (octets) {
                $traffic.append($('<span/>').addClass('lens-bar').css(
                    'width', largest ? Math.max(2, (octets / largest) * 100) + '%' : 0
                ));
                $traffic.append($('<span/>').addClass('lens-bytes').text(bytes(octets)));
            }

            return $('<tr/>').addClass('lens-group')
                .append($name)
                .append($traffic)
                .append($('<td/>'))
                .append($('<td/>').append($('<span/>').addClass(here ? 'lens-here' : '')
                    .text(here + ' {{ lang._("here now") }}')))
                .append($('<td/>'))
                .append($('<td/>').addClass('lens-cause')
                    .text('{{ lang._("Click the name to open this group.") }}'));
        };

        const deviceRow = (device, largest) => {
            const $name = $('<td/>');
            $name.append($('<i/>')
                .addClass('fa fa-fw lens-icon ' + device.kind.icon)
                .attr('title', device.kind.type));
            $name.append($('<a/>').addClass('lens-name').attr('href', '#')
                .text(device.name)
                .on('click', function (event) {
                    event.preventDefault();
                    openDetail(device);
                }));
            $name.append($('<a/>').addClass('lens-edit').attr('href', '#')
                .attr('title', '{{ lang._("Give this device a name of your own") }}')
                .append($('<i/>').addClass('fa fa-pencil'))
                .on('click', function (event) {
                    event.preventDefault();
                    openEditor(device);
                }));
            $name.append($('<span/>').addClass('lens-mac').text(device.mac));
            if (device.role) {
                $name.append($('<span/>').addClass('lens-role').text(device.role));
            }
            for (const tag of device.tags) {
                $name.append($('<span/>').addClass('lens-tag').text(tag));
            }
            if (device.label.note) {
                $name.append($('<span/>').addClass('lens-note').text(device.label.note));
            }

            const $traffic = $('<td/>').addClass('lens-traffic');
            if (device.octets) {
                $traffic.append($('<span/>').addClass('lens-bar').css(
                    'width', largest ? Math.max(2, (device.octets / largest) * 100) + '%' : 0
                ));
                $traffic.append($('<span/>').addClass('lens-bytes').text(device.traffic));
            }

            const $addresses = $('<td/>');
            if (!device.addresses.length) {
                $addresses.text('{{ lang._("no address recorded") }}');
            }
            device.addresses.forEach((address, index) => {
                const $line = $('<span/>').addClass('lens-addr').text(address.address);
                $line.append($('<span/>').addClass('lens-if').text(' \u00b7 ' + address.interface));
                if (!address.current) {
                    $line.addClass('lens-addr-gone')
                         .append($('<span/>').text(' (' + address.seen + ')'));
                }
                /* the firewall holds twelve addresses and drowned every other
                   row on the page; the rest are one click away */
                if (index >= 3) {
                    $line.addClass('lens-addr-more').hide();
                }
                $addresses.append($line);
            });
            if (device.addresses.length > 3) {
                $addresses.append($('<a/>')
                    .addClass('lens-addr-toggle').attr('href', '#')
                    .text('+ ' + (device.addresses.length - 3) + ' {{ lang._("more") }}')
                    .on('click', function (event) {
                        event.preventDefault();
                        $(this).siblings('.lens-addr-more').toggle();
                        $(this).text($(this).siblings('.lens-addr-more:visible').length
                            ? '{{ lang._("show fewer") }}'
                            : '+ ' + (device.addresses.length - 3) + ' {{ lang._("more") }}');
                    }));
            }

            const $presence = $('<td/>').append(
                $('<span/>').addClass(device.here ? 'lens-here' : '').text(device.presence)
            );

            const $named = $('<td/>').addClass('lens-cause').text(device.named_by);
            if (device.caveat) {
                $named.append($('<em/>').addClass('lens-caveat').text(device.caveat));
            }

            return $('<tr/>')
                .append($name)
                .append($traffic)
                .append($addresses)
                .append($presence)
                .append(cell(device.known_for))
                .append($named);
        };

        /*
         * The chart is hand-drawn SVG rather than a charting library: it is
         * twenty-four stacked bars, the page already ships no dependencies, and
         * a library would decide the axis and the rounding for us. Sent below,
         * received above, one bar per hour, scaled to the busiest hour shown.
         */
        const drawChart = (detail) => {
            const svg = document.getElementById('lensChart');
            while (svg.firstChild) {
                svg.removeChild(svg.firstChild);
            }

            const points = detail.series;
            if (!points.length) {
                return;
            }

            const width = 640;
            const height = 140;
            const gap = points.length > 60 ? 0 : 1;
            const step = width / points.length;
            const peak = detail.peak || 1;

            svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);

            const bar = (x, y, w, h, klass, title, point) => {
                const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                if (point && point.total) {
                    /* a bar says a device moved 4 GB in that hour; the only
                       question after that is what it was made of */
                    rect.setAttribute('style', 'cursor: pointer');
                    rect.addEventListener('click', () => openMoment(detail, point));
                }
                rect.setAttribute('x', x);
                rect.setAttribute('y', y);
                rect.setAttribute('width', Math.max(0.5, w));
                rect.setAttribute('height', Math.max(0, h));
                rect.setAttribute('class', klass);
                const label = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                label.textContent = title;
                rect.appendChild(label);
                svg.appendChild(rect);
            };

            points.forEach((point, index) => {
                const x = index * step;
                const w = step - gap;
                const down = (point.received / peak) * height;
                const up = (point.sent / peak) * height;
                const when = new Date(point.bucket * 1000).toLocaleString();
                const title = when + ' \u2014 ' + bytes(point.sent) + ' up, '
                    + bytes(point.received) + ' down';

                /* an hour with nothing in it still gets a mark, so a quiet hour
                   cannot be mistaken for an hour Lens has no data for */
                if (!point.total) {
                    bar(x, height - 1, w, 1, 'lens-chart-empty', when + ' \u2014 nothing');
                    return;
                }
                bar(x, height - down - up, w, down, 'lens-chart-down', title, point);
                bar(x, height - up, w, up, 'lens-chart-up', title, point);
            });
        };

        /* the same units as the table, without a second round trip to get them */
        const bytes = (octets) => {
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

        const openMoment = (detail, point) => {
            const $block = $('#lensMoment').show();
            $('#lensMomentWhen').text(new Date(point.bucket * 1000).toLocaleString());
            $('#lensMomentRows').empty()
                .append($('<tr/>').append($('<td/>').text('{{ lang._("reading...") }}')));

            ajaxGet('/api/lens/devices/moment',
                    { mac: detail.mac, at: point.bucket, step: detail.step },
                    (moment, status) => {
                const $rows = $('#lensMomentRows').empty();

                if (status !== 'success' || !moment || !moment.addresses) {
                    $rows.append($('<tr/>').append($('<td/>')
                        .text('{{ lang._("That slice did not come back.") }}')));
                    return;
                }

                if (!moment.addresses.length) {
                    $rows.append($('<tr/>').append($('<td/>')
                        .text('{{ lang._("Nothing of this device could be attributed in that slice.") }}')));
                    return;
                }

                for (const row of moment.addresses) {
                    $rows.append($('<tr/>')
                        .append($('<td/>').text(row.address))
                        .append($('<td/>').addClass('lens-if').text(row.interface))
                        .append($('<td/>').addClass('lens-traffic').text(
                            row.traffic + ' (' + row.sent + ' \u2191, ' + row.received + ' \u2193)')));
                }
            });
        };

        const openDetail = (device) => {
            $('#lensDetailName').text(device.name);
            $('#lensDetailMac').text(device.mac);
            $('#lensDetailNote').hide();
            $('#lensMoment').hide();
            $('#lensDetailBody').hide();
            $('#lensDetailLoading').show();
            $('#lensDetail').modal('show');

            ajaxGet('/api/lens/devices/history',
                    { mac: device.mac, hours: chosenHours() }, (detail, detailStatus) => {
                $('#lensDetailLoading').hide();

                if (detailStatus !== 'success' || !detail || !detail.series) {
                    $('#lensDetailNote').text('{{ lang._("No history came back.") }}').show();
                    return;
                }

                $('#lensDetailStep').text(detail.step_name);
                $('#lensDetailWindow').text(LABELS[chosenHours()]);
                $('#lensDetailTotal').text(detail.total);
                $('#lensDetailSent').text(detail.sent);
                $('#lensDetailReceived').text(detail.received);
                $('#lensDetailPeak').text(detail.busiest
                    ? detail.busiest.what + ' {{ lang._("at") }} '
                      + new Date(detail.busiest.bucket * 1000).toLocaleString()
                    : '{{ lang._("nothing measured in this window") }}');
                $('#lensDetailIfs').text(detail.interfaces.join(', ') || '\u2014');

                if (detail.note) {
                    $('#lensDetailNote').text(detail.note).show();
                }

                drawChart(detail);
                $('#lensDetailBody').show();
            });
        };

        const openEditor = (device) => {
            editing = device;

            $('#lensEditFor').text(device.mac);
            $('#lensEditName').val(device.label.name);
            $('#lensEditTags').val(device.label.tags);
            $('#lensEditNote').val(device.label.note);

            const $kind = $('#lensEditKind').empty();
            $('<option/>').val('').text(
                '{{ lang._("work it out from the hardware vendor") }}'
                + ' \u2014 ' + device.kind.type
            ).appendTo($kind);
            for (const key of Object.keys(kinds)) {
                $('<option/>').val(key).text(kinds[key]).appendTo($kind);
            }
            $kind.val(device.label.kind || '');

            $('#lensEditError').hide();
            $('#lensEditor').modal('show');
        };

        const saveLabel = () => {
            if (!editing) {
                return;
            }

            const payload = {
                mac: editing.mac,
                name: $('#lensEditName').val(),
                kind: $('#lensEditKind').val(),
                tags: $('#lensEditTags').val(),
                note: $('#lensEditNote').val()
            };

            $('#lensEditSave').prop('disabled', true);
            ajaxCall('/api/lens/devices/label', payload, (reply, saveStatus) => {
                $('#lensEditSave').prop('disabled', false);

                if (saveStatus !== 'success' || !reply || reply.status !== 'ok') {
                    $('#lensEditError')
                        .text((reply && reply.message) || '{{ lang._("The name was not saved.") }}')
                        .show();
                    return;
                }

                /* re-read rather than patch the row in place: the name changes
                   what the search matches and what the label column says, and
                   one source of truth is cheaper than keeping two agreeing */
                $('#lensEditor').modal('hide');
                load();
            });
        };

        const drawSummary = (summary) => {
            if (!summary.known) {
                return;
            }

            const cell = (figure, caption, warn) => $('<div/>').addClass('lens-stat')
                .append($('<div/>').addClass('lens-figure' + (warn ? ' lens-degraded' : ''))
                    .text(figure))
                .append($('<div/>').addClass('lens-caption').text(caption));

            const $strip = $('#lensSummary').empty();

            $strip.append(cell(
                summary.here + ' / ' + summary.known,
                '{{ lang._("devices here now, of all Lens knows") }}'));

            $strip.append(cell(
                summary.moved,
                '{{ lang._("attributed to a device in 24 hours") }}'));

            if (summary.busiest) {
                $strip.append(cell(summary.busiest.what, summary.busiest.name));
            }

            /* "new" is only a statement about the network once Lens has been
               watching longer than the window it is comparing against */
            if (!summary.new_yet) {
                $strip.append(cell(
                    summary.watching_for,
                    '{{ lang._("watching so far - too short to call anything new") }}'));
            } else if (summary.new.length) {
                $strip.append(cell(
                    summary.new.length,
                    '{{ lang._("seen for the first time in 24 hours") }}: '
                        + summary.new.slice(0, 3).join(', ')
                        + (summary.new.length > 3 ? ', ...' : ''),
                    true));
            } else {
                $strip.append(cell(
                    '0', '{{ lang._("devices new in the last 24 hours") }}'));
            }

            if (summary.away) {
                $strip.append(cell(
                    summary.away, '{{ lang._("not seen for over a day") }}'));
            }

            $strip.show();
        };

        const drawSegments = () => {
            const counts = new Map();
            for (const device of devices) {
                for (const name of device.interfaces) {
                    counts.set(name, (counts.get(name) || 0) + 1);
                }
            }

            const $bar = $('#lensSegments').empty();
            for (const [name, count] of [...counts.entries()].sort()) {
                $('<a/>').addClass('lens-chip').attr('href', '#')
                    .toggleClass('lens-chip-on', segments.has(name))
                    .text(name + ' (' + count + ')')
                    .on('click', function (event) {
                        event.preventDefault();
                        if (segments.has(name)) {
                            segments.delete(name);
                        } else {
                            segments.add(name);
                        }
                        $(this).toggleClass('lens-chip-on', segments.has(name));
                        render();
                    })
                    .appendTo($bar);
            }
        };

        const load = () => ajaxGet('/api/lens/devices/list', { hours: chosenHours() },
                                   (report, deviceStatus) => {
            if (deviceStatus !== 'success' || !report || !report.devices) {
                $('#lensDevicesError').show();
                return;
            }

            devices = report.devices;
            kinds = report.kinds || {};
            drawRange(report.window || {});
            drawSummary(report.summary || {});
            groups = report.groups || [];
            $('#lensGroupWrap').toggle(groups.length > 0);
            $('#lensDevicesHeadline').text(report.headline || '');

            if (report.note) {
                $('#lensDevicesNote').text(report.note).show();
            }

            drawSegments();
            render();

            $('#lensControls').toggle(devices.length > 0);

            const accounting = report.accounting || {};
            $('#lensAttributed').text(accounting.attributed || '0 B');
            $('#lensAttributedHours').text(accounting.hours || 0);

            const $acc = $('#lensAccounting > tbody').empty();
            for (const row of (accounting.rows || [])) {
                $acc.append($('<tr/>')
                    .append($('<td/>').addClass('lens-traffic').text(row.what))
                    .append($('<td/>').addClass('lens-cause').text(row.why)));
            }
            const $worst = $('#lensUnexplained > tbody').empty();
            for (const row of (accounting.unexplained || [])) {
                $worst.append($('<tr/>')
                    .append($('<td/>').addClass('lens-traffic').text(row.what))
                    .append($('<td/>').text(row.address))
                    .append($('<td/>').addClass('lens-if').text(row.interface))
                    .append($('<td/>').addClass('lens-if').text(row.reason))
                    .append($('<td/>').addClass('lens-if').text(
                        row.hours + ' {{ lang._("hours") }}')));
            }
            $('#lensUnexplainedBlock').toggle((accounting.unexplained || []).length > 0);

            if ((accounting.rows || []).length) {
                $('#lensAccountingBlock').show();
            }

            $('#lensDevicesBlock').show();
        });

        load();
        $('#lensUnexplainedToggle').on('click', function (event) {
            event.preventDefault();
            $('#lensUnexplainedList').toggle();
            $(this).text($('#lensUnexplainedList').is(':visible')
                ? '{{ lang._("Hide the addresses") }}'
                : '{{ lang._("Show which addresses those are") }}');
        });
        $('#lensSearch').on('input', render);
        $('#lensOnlyTraffic').on('change', render);
        $('#lensGroup').on('change', render);
        $('#lensEditSave').on('click', saveLabel);

        ajaxGet('/api/lens/sources/report', {}, (report, requestStatus) => {
            $('#lensLoading').hide();

            if (requestStatus !== 'success' || !report || !report.sources) {
                $('#lensError').show();
                return;
            }

            $('#lensVersion').text(report.version || '{{ lang._("unknown") }}');

            const ready = report.sources.filter(s => s.verdict === 'ready').length;
            $('#lensHeadline').text(
                ready + ' {{ lang._("of") }} ' + report.sources.length
                + ' {{ lang._("data sources are ready.") }}'
            );

            const $body = $('#lensSources > tbody').empty();
            for (const source of report.sources) {
                $body.append($('<tr/>')
                    .append(cell(source.label))
                    .append(verdictCell(source.verdict))
                    .append(cell(source.headline))
                    .append(causeCell(source))
                    .append(cell(source.enables)));
            }

            /* coverage: the check that would have caught this box a week earlier */
            const netflow = report.sources.find(s => s.id === 'netflow');
            if (netflow) {
                $('#lensCaptured').text(list(netflow.captured));
                $('#lensMissing').text(list(netflow.missing));
                $('#lensCoverage').show();
            }

            const $ret = $('#lensRetention > tbody').empty();
            for (const row of (report.retention || [])) {
                $ret.append($('<tr/>').append(cell(row.what)).append(cell(row.detail)));
            }

            const calls = Object.entries(report.timing.calls || {})
                .sort((a, b) => b[1] - a[1])
                .map(([name, ms]) => name + ' ' + ms + ' ms')
                .join(' · ');
            $('#lensTimingTotal').text(report.timing.total_ms);
            $('#lensTimingCalls').text(calls);

            $('#lensReport').show();
        });

        /* what Lens itself has kept -- the only thing on this page that is ours */
        ajaxGet('/api/lens/store/status', {}, (store, storeStatus) => {
            if (storeStatus !== 'success' || !store) {
                return;
            }

            $('#lensStoreHeadline').text(store.headline);

            const $body = $('#lensStore > tbody').empty();
            for (const row of (store.rows || [])) {
                $body.append($('<tr/>')
                    .append(cell(row.what))
                    .append($('<td/>').addClass(row.wrong ? 'lens-degraded' : '').text(row.detail)));
            }

            $('#lensStoreBlock').show();
        });

    });
</script>

<div id="lensDevicesError" class="alert alert-danger" style="display: none;">
    {{ lang._('The device list did not come back. The store may not exist yet - the collector creates it on its first run.') }}
</div>

<div id="lensDevicesBlock" style="display: none;">
    <h3>{{ lang._('Devices') }}</h3>
    <p id="lensDevicesHeadline"></p>
    <div id="lensRangeWrap">
        <span id="lensRange" style="display: none;"></span>
        <span id="lensRangeNote" class="text-muted" style="display: none;"></span>
    </div>

    <div id="lensSummary" style="display: none;"></div>

    <div id="lensDevicesNote" class="alert alert-warning" style="display: none;"></div>

    <div id="lensControls" style="display: none;">
        <input type="text" id="lensSearch" class="form-control input-sm"
               style="display: inline-block;"
               placeholder="{{ lang._('Search a name, address, MAC or vendor') }}">
        <label style="font-weight: normal; margin: 0 0 0 10px;">
            <input type="checkbox" id="lensOnlyTraffic"> {{ lang._('only devices with traffic') }}
        </label>
        <label id="lensGroupWrap" style="font-weight: normal; margin: 0 0 0 10px; display: none;">
            <input type="checkbox" id="lensGroup" checked> {{ lang._('group similar devices') }}
        </label>
        <span id="lensShowing"></span>
        <div id="lensSegments"></div>
    </div>

    <div id="lensEmpty" class="alert alert-info" style="display: none;">
        {{ lang._('No device matches. Clear the search, or switch the segment filters off.') }}
    </div>

    <table id="lensDevices" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Device') }}</th>
                <th>{{ lang._('Traffic') }}</th>
                <th>{{ lang._('Addresses held') }}</th>
                <th>{{ lang._('Presence') }}</th>
                <th>{{ lang._('Known for') }}</th>
                <th>{{ lang._('How Lens names it') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <p class="text-muted">
        {{ lang._('A device can hold several addresses at once, on different interfaces. They are listed, not merged: merging them would report one machine as two, at half its traffic each.') }}
        {{ lang._('Traffic is joined onto whoever held the address at the hour it was measured, not onto whoever holds it now.') }}
    </p>

    <div class="modal" id="lensDetail" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">
                        <span id="lensDetailName"></span>
                        <span id="lensDetailMac" class="lens-mac"></span>
                    </h4>
                </div>
                <div class="modal-body">
                    <div id="lensDetailLoading">
                        <i class="fa fa-spinner fa-spin"></i>
                        {{ lang._('Reading this device out of the store...') }}
                    </div>
                    <div id="lensDetailNote" class="alert alert-info" style="display: none;"></div>
                    <div id="lensDetailBody" style="display: none;">
                        <svg id="lensChart" class="lens-chart"
                             preserveAspectRatio="none"></svg>
                        <div id="lensMoment" class="lens-block" style="display: none;">
                            <b>{{ lang._('What that slice was made of') }}</b>
                            &mdash; <span id="lensMomentWhen"></span>
                            <table class="table table-condensed">
                                <tbody id="lensMomentRows"></tbody>
                            </table>
                        </div>

                        <p class="text-muted lens-chart-legend">
                            {{ lang._('One bar per') }} <span id="lensDetailStep"></span>,
                            {{ lang._('newest on the right.') }}
                            {{ lang._('A bar with something in it opens the addresses behind it.') }}
                            <span class="lens-key lens-chart-up"></span> {{ lang._('sent') }}
                            <span class="lens-key lens-chart-down"></span> {{ lang._('received') }}
                            &mdash; {{ lang._('an hour with nothing in it keeps a thin line, so quiet cannot be mistaken for missing.') }}
                        </p>
                        <table class="table table-condensed">
                            <tbody>
                                <tr>
                                    <td style="width: 14em;">
                                        {{ lang._('Over') }} <span id="lensDetailWindow"></span>
                                    </td>
                                    <td>
                                        <span id="lensDetailTotal"></span>
                                        (<span id="lensDetailSent"></span> {{ lang._('up') }},
                                        <span id="lensDetailReceived"></span> {{ lang._('down') }})
                                    </td>
                                </tr>
                                <tr>
                                    <td>{{ lang._('Busiest hour') }}</td>
                                    <td id="lensDetailPeak"></td>
                                </tr>
                                <tr>
                                    <td>{{ lang._('Seen on') }}</td>
                                    <td id="lensDetailIfs"></td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-muted">
                            {{ lang._('Only hours this device could be identified in are counted. An hour it shared an address with another device is left out of both, and appears in the accounting below the list.') }}
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-dismiss="modal">{{ lang._('Close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="lensEditor" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">{{ lang._('What do you call this device?') }}</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        {{ lang._('Kept apart from what the box observed, against') }}
                        <span id="lensEditFor" class="lens-mac"></span>.
                        {{ lang._('It survives a new address, a new lease and a rename, and no observation ever writes over it.') }}
                    </p>
                    <div id="lensEditError" class="alert alert-danger" style="display: none;"></div>
                    <div class="form-group">
                        <label for="lensEditName">{{ lang._('Name') }}</label>
                        <input type="text" class="form-control" id="lensEditName"
                               placeholder="{{ lang._('leave empty to keep the observed name') }}">
                    </div>
                    <div class="form-group">
                        <label for="lensEditKind">{{ lang._('Kind') }}</label>
                        <select class="form-control" id="lensEditKind"></select>
                    </div>
                    <div class="form-group">
                        <label for="lensEditTags">{{ lang._('Tags') }}</label>
                        <input type="text" class="form-control" id="lensEditTags"
                               placeholder="{{ lang._('comma separated, e.g. hypervisor, production') }}">
                    </div>
                    <div class="form-group">
                        <label for="lensEditNote">{{ lang._('Note') }}</label>
                        <input type="text" class="form-control" id="lensEditNote">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="lensEditSave">{{ lang._('Save') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div id="lensAccountingBlock" class="lens-block" style="display: none;">
        <h3>{{ lang._('What the traffic above does not cover') }}</h3>
        <p>
            <span id="lensAttributed"></span>
            {{ lang._('was attributed to a device over the last') }}
            <span id="lensAttributedHours"></span> {{ lang._('hours. The rest is here, rather than quietly missing.') }}
        </p>
        <table id="lensAccounting" class="table table-condensed">
            <tbody></tbody>
        </table>

        <div id="lensUnexplainedBlock" style="display: none;">
            <p>
                <a href="#" id="lensUnexplainedToggle">{{ lang._('Show which addresses those are') }}</a>
            </p>
            <div id="lensUnexplainedList" style="display: none;">
            <table id="lensUnexplained" class="table table-condensed table-striped">
                <thead>
                    <tr>
                        <th style="width: 9em;">{{ lang._('Bytes') }}</th>
                        <th>{{ lang._('Address') }}</th>
                        <th>{{ lang._('Interface') }}</th>
                        <th>{{ lang._('Why') }}</th>
                        <th>{{ lang._('Seen in') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p class="text-muted">
                {{ lang._('The heaviest twenty-five, biggest first. One repeated subnet here usually means a network routed through this firewall rather than attached to it - those addresses have no MAC on any of its segments and never will.') }}
            </p>
            </div>
        </div>
    </div>
</div>

<div id="lensLoading">
    <i class="fa fa-spinner fa-spin"></i>
    {{ lang._('Asking the box what it can tell Lens...') }}
</div>

<div id="lensError" class="alert alert-danger" style="display: none;">
    {{ lang._('The report did not come back. Lens is installed, but something between this page and configd is not working.') }}
</div>

<div id="lensReport" style="display: none;">
    <p id="lensHeadline"></p>

    <table id="lensSources" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Source') }}</th>
                <th>{{ lang._('State') }}</th>
                <th>{{ lang._('Now') }}</th>
                <th>{{ lang._('Why, and what would change it') }}</th>
                <th>{{ lang._('What it makes possible') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <div id="lensCoverage" class="lens-block" style="display: none;">
        <h3>{{ lang._('Traffic capture coverage') }}</h3>
        <p>{{ lang._('A device on an interface that is not captured produces no traffic history at all, and no other page will mention it.') }}</p>
        <table class="table table-condensed">
            <tbody>
                <tr>
                    <td style="width: 12em;">{{ lang._('Captured') }}</td>
                    <td id="lensCaptured"></td>
                </tr>
                <tr>
                    <td>{{ lang._('Not captured') }}</td>
                    <td id="lensMissing"></td>
                </tr>
            </tbody>
        </table>
    </div>


    <div id="lensStoreBlock" class="lens-block" style="display: none;">
        <h3>{{ lang._('What Lens has kept') }}</h3>
        <p id="lensStoreHeadline"></p>
        <table id="lensStore" class="table table-condensed">
            <tbody></tbody>
        </table>
        <p class="text-muted">
            {{ lang._('Hourly traffic per device exists in OPNsense for 24 hours. Everything above that line was copied out before it was deleted, and cannot be recovered any other way.') }}
        </p>
    </div>

    <div class="lens-block">
        <h3>{{ lang._('How far back the data goes') }}</h3>
        <p>{{ lang._('These limits are fixed in OPNsense itself, not a setting. Actual depth is also bounded by when capture was switched on, which is the earlier of the two.') }}</p>
        <table id="lensRetention" class="table table-condensed">
            <thead>
                <tr>
                    <th style="width: 24em;">{{ lang._('What is kept') }}</th>
                    <th>{{ lang._('For how long') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <p id="lensTiming" class="text-muted">
        {{ lang._('Lens version') }} <span id="lensVersion"></span>.
        {{ lang._('This report took') }} <span id="lensTimingTotal"></span> ms
        &mdash; <span id="lensTimingCalls"></span>.
        {{ lang._('It runs once when the page is opened, never on a timer.') }}
    </p>
</div>
