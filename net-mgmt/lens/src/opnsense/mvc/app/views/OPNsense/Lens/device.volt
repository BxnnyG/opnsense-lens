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
        const mac = (new URLSearchParams(location.search).get('mac') || '').toLowerCase();
        const LABELS = { 24: '{{ lang._("24 hours") }}', 168: '{{ lang._("7 days") }}',
                         720: '{{ lang._("30 days") }}' };
        let hours = 24;
        let kinds = {};
        let device = null;
        /* every MAC this device is, once a phone's rotating addresses are one row (§4.61) */
        const macs = () => (device && device.macs ? device.macs : [mac]).join(',');
        /* interface to the name Networks uses, from this device's own history */
        let segmentOf = {};

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

            ajaxGet('/api/lens/devices/history', { mac: macs(), hours: hours }, (detail, status) => {
                const node = document.getElementById('dvChart');
                while (node.firstChild) {
                    node.removeChild(node.firstChild);
                }
                if (status !== 'success' || !detail || !detail.series) {
                    return;
                }

                const width = 800;
                const height = 170;
                const plot = height;
                const points = detail.series;
                const step = width / Math.max(1, points.length);
                /* a surface gap between neighbouring bars while they are wide
                   enough to keep one; a month of hours is a comb without it */
                const gap = points.length > 60 ? 0 : 2;
                const peak = detail.peak || 1;
                node.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
                node.appendChild(svg('line', { x1: 0, x2: width, y1: plot - 0.5, y2: plot - 0.5, class: 'lens-gridline' }));

                /* at most 24 units wide, centred in its slot: a bar that fills
                   the slot reads as a block, and the air is what separates days */
                const w = Math.max(0.5, Math.min(24, step - gap));
                points.forEach((point, index) => {
                    const x = index * step + (step - w) / 2;
                    const readout = () => [
                        { label: Lens.when(point.bucket, detail.step, true) },
                        { value: bytes(point.received), label: '{{ lang._("received") }}', key: 'var(--lens-received)' },
                        { value: bytes(point.sent), label: '{{ lang._("sent") }}', key: 'var(--lens-sent)' },
                    ];
                    if (!point.total) {
                        node.appendChild(svg('rect', { x: x, y: plot - 1, width: w, height: 1,
                                                       class: 'dv-empty' }));
                        return;
                    }
                    const down = point.received / peak * (plot - 6);
                    const up = point.sent / peak * (plot - 6);
                    for (const [y, h, cls] of [[plot - down - up, down, 'dv-down'],
                                                [plot - up, up, 'dv-up']]) {
                        const rect = svg('rect', { x: x, y: y, width: w, height: Math.max(0, h),
                                                   class: cls, tabindex: 0 });
                        rect.addEventListener('click', () => moment(detail, point));
                        Lens.tip(rect, readout);
                        node.appendChild(rect);
                    }
                });
                Lens.axis(document.getElementById('dvChartAxis'), points.map(p => ({ at: p.bucket })),
                          (i) => (i * step + step / 2) / width, detail.step);

                $('#dvChartTotal').text(detail.total + ' · ' + detail.sent + ' up · '
                                        + detail.received + ' down');
                $('#dvChartBusy').text(detail.busiest
                    ? '{{ lang._("busiest") }} ' + detail.busiest.what + ' · '
                      + Lens.when(detail.busiest.bucket, detail.step, true)
                    : '');
            });
        };

        /* a bar opens the addresses behind it; the rows add up to the bar (§4.44) */
        const moment = (detail, point) => {
            $('#dvMomentWhen').text(Lens.when(point.bucket, detail.step, true));
            const $rows = $('#dvMomentRows').empty();
            $('#dvMoment').show();
            ajaxGet('/api/lens/devices/moment', { mac: macs(), at: point.bucket, step: detail.step },
                    (data, status) => {
                if (status !== 'success' || !data || !data.addresses) {
                    return;
                }
                for (const row of data.addresses) {
                    $rows.append($('<tr/>')
                        .append($('<td/>').text(row.address))
                        .append($('<td/>').addClass('dv-sub').text(segmentOf[row.interface] || row.interface))
                        .append($('<td/>').text(row.traffic + ' (' + row.sent + ' \u2191, '
                                                + row.received + ' \u2193)')));
                }
            });
        };

        /* ------------------------------------------------ the week */
        /* a 7 x 24 grid into $hm: the traffic week and the DNS week draw alike */
        const drawGrid = (grid, $hm) => {
            $hm.empty().append($('<div/>'));
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
        };
        const heatmap = (grid) => {
            drawGrid(grid, $('#dvHeatmap'));
            $('#dvHeatmapNote').text(grid.empty
                ? '{{ lang._("Nothing measured in the last four weeks.") }}'
                : '{{ lang._("Four weeks folded onto one, local time. Busiest:") }} ' + grid.peak
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
            /* the strip, hour by hour, as Who's home and the uptime line draw time (stage 55) */
            $strip.removeClass('dv-strip').append(Lens.strip(mine.spans, report.start, report.now, 168, mine.name || ''));
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
                .append(document.createTextNode(' · ' + device.kind.type))
                /* how sure, in words (§4.66) */
                .append($('<span/>').addClass('dv-sub').text(' (' + device.kind.basis + ')'));

            const $pills = $('#dvPills').empty();
            $pills.append($('<span/>').addClass('dv-pill ' + (device.here ? 'home' : 'away'))
                .text(device.here ? '{{ lang._("home now") }}' : device.presence));
            if (device.role) {
                $pills.append($('<span/>').addClass('dv-pill').text(device.role));
            }
            if (device.folded) {
                const $fold = $('<span/>').addClass('dv-pill warn')
                    .text(device.macs.length + ' {{ lang._("private addresses, one device") }}');
                Lens.tip($fold[0], [{ label: device.folded }]);
                $pills.append($fold);
            } else if (device.randomised) {
                $pills.append($('<span/>').addClass('dv-pill warn').attr('title', device.caveat)
                    .text('{{ lang._("randomised MAC") }}'));
            }
            if (device.owner) {
                $pills.append($('<span/>').addClass('dv-pill')
                    .append($('<i/>').addClass('fa fa-user'))
                    .append(document.createTextNode(' ' + device.owner)));
            }
            if (device.muted) {
                $pills.append($('<span/>').addClass('dv-pill')
                    .attr('title', '{{ lang._("Its events fold away on Events, and the one sentence never leads with it. Its figures stay.") }}')
                    .text('{{ lang._("muted") }}'));
            }
            for (const tag of device.tags) {
                $pills.append($('<span/>').addClass('dv-pill').text(tag));
            }
            $('#dvMute').empty()
                .append($('<i/>').addClass('fa ' + (device.muted ? 'fa-bell' : 'fa-bell-slash')))
                .append(document.createTextNode(' ' + (device.muted
                    ? '{{ lang._("Unmute") }}' : '{{ lang._("Mute") }}')))
                .attr('title', device.muted
                    ? '{{ lang._("Tell me about this device again") }}'
                    : '{{ lang._("Stop telling me about this device: its events fold away, its figures stay") }}');
            /* deleting lives on Services: Lens: Privacy, behind its privilege (§4.72) */
            $('#dvCompare').attr('href', '/ui/lens/compare?devices='
                + encodeURIComponent((device.macs || [device.mac]).join(',')));
            $('#dvForget').attr('href', '/ui/lens/privacy?mac='
                + encodeURIComponent((device.macs || [device.mac]).join(',')));
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
            segmentOf = {};
            for (const entry of profile.story) {
                segmentOf[entry.interface] = entry.segment || entry.interface;
            }
            for (const entry of profile.story) {
                $story.append($('<li/>').toggleClass('current', entry.current)
                    .append($('<span/>').addClass('addr').text(entry.address))
                    .append(document.createTextNode(' · ' + (entry.segment || entry.interface)))
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
            talks();
            pauseState();
        });

        /* ------------------------------------------------ pause (§4.74) */
        let pauseMinutes = 60;
        const clock = (at) => Lens.stamp(at);
        /* the page asks; whether it may is decided on the box (§4.35), and a
           user without the privilege gets no button at all */
        const pauseState = () => ajaxGet('/api/lens/pause/device', { mac: mac }, (reply, status) => {
            if (status !== 'success' || !reply || reply.status !== 'ok') {
                $('#dvPause, #dvPaused').hide();
                return;
            }
            const pause = reply.pause;
            $('#dvPause').toggle(!pause);
            $('#dvPauseBox').hide();
            if (!pause) {
                $('#dvPaused').hide();
                $('#dvPauseRefused').text(reply.refused || '').toggle(!!reply.refused);
                $('#dvPauseForm').toggle(!reply.refused);
                $('#dvPauseUnguarded').toggle(!(reply.guarded || []).length);
                return;
            }
            let text;
            if (pause.by === 'alias') {
                text = '{{ lang._("Its MAC is in the alias lens_paused, put there outside Lens.") }}';
            } else {
                text = '{{ lang._("Paused since") }} ' + clock(pause.since) + ', '
                    + (pause.until ? '{{ lang._("until") }} ' + clock(pause.until) + '.'
                        : '{{ lang._("until it is resumed.") }}')
                    + (pause.reason ? ' {{ lang._("Why:") }} ' + pause.reason : '');
            }
            if (!pause.effective) {
                text += ' ' + (reply.rule === 'disabled'
                    ? '{{ lang._("The rule Lens: paused devices is switched off in Firewall: Rules, so nothing is blocked.") }}'
                    : '{{ lang._("The rule Lens: paused devices is missing, so nothing is blocked.") }}');
            }
            $('#dvPausedText').text(text);
            $('#dvResume').show();
            $('#dvPaused').show();
        });

        const morning = () => {
            const next = new Date();
            next.setHours(6, 0, 0, 0);
            if (next <= new Date()) {
                next.setDate(next.getDate() + 1);
            }
            return Math.ceil((next - new Date()) / 60000);
        };

        $('#dvPause').on('click', (event) => {
            event.preventDefault();
            $('#dvPauseError').hide();
            $('#dvPauseBox').slideToggle(120);
        });
        $('#dvPauseCancel').on('click', (event) => {
            event.preventDefault();
            $('#dvPauseBox').slideUp(120);
        });
        /* until a moment the operator picks, up to the seven days a pause may last (operator, 2026-10-09) */
        const local = (date) => {
            const pad = n => String(n).padStart(2, '0');
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
        };
        const untilPicked = () => {
            const picked = new Date($('#dvPauseUntil').val());
            const minutes = Math.ceil((picked - new Date()) / 60000);
            const fits = !isNaN(minutes) && minutes >= 1 && minutes <= 7 * 24 * 60;
            $('#dvPauseUntilNote').text(fits ? '' : '{{ lang._("Pick a time within the next seven days.") }}');
            $('#dvPauseGo').prop('disabled', !fits);
            return fits ? minutes : null;
        };
        $('#dvPauseFor').on('click', '.lens-chip', function (event) {
            event.preventDefault();
            $('#dvPauseFor .lens-chip').removeClass('lens-chip-on');
            $(this).addClass('lens-chip-on');
            const wanted = $(this).data('minutes');
            $('#dvPauseUntilBox').toggle(wanted === 'pick');
            $('#dvPauseGo').prop('disabled', false);
            if (wanted === 'pick') {
                const now = new Date();
                const later = new Date(now.getTime() + 3 * 3600 * 1000);
                $('#dvPauseUntil').attr({ min: local(now), max: local(new Date(now.getTime() + 7 * 86400 * 1000)) })
                    .val($('#dvPauseUntil').val() || local(later));
                pauseMinutes = untilPicked();
                return;
            }
            pauseMinutes = wanted === 'morning' ? morning() : parseInt(wanted, 10);
        });
        $('#dvPauseUntil').on('input change', () => {
            pauseMinutes = untilPicked();
        });
        $('#dvPauseGo').on('click', () => {
            Lens.busy($('#dvPauseGo')[0], true, '{{ lang._("Pausing") }}');
            ajaxCall('/api/lens/pause/pause', { mac: mac, minutes: String(pauseMinutes),
                                                reason: $('#dvPauseReason').val() || '' }, (reply, status) => {
                Lens.busy($('#dvPauseGo')[0], false);
                if (status !== 'success' || !reply || reply.status !== 'ok') {
                    $('#dvPauseError').text((reply && reply.message)
                        || '{{ lang._("The device was not paused.") }}').show();
                    return;
                }
                /* the pause card closes on success, so this goes above the page */
                if (reply.message) {
                    $('#dvError').text(reply.message).show();
                }
                pauseState();
            });
        });
        $('#dvResume').on('click', () => {
            Lens.busy($('#dvResume')[0], true, '{{ lang._("Resuming") }}');
            ajaxCall('/api/lens/pause/resume', { mac: macs() }, (reply, status) => {
                Lens.busy($('#dvResume')[0], false);
                if (status === 'success' && reply && reply.status === 'ok') {
                    pauseState();
                }
            });
        });

        /* ------------------------------------------------ where it talks (§4.62) */
        let talkDays = 30;
        const talks = () => {
            $('#dvTalkRange .lens-chip').each(function () {
                $(this).toggleClass('lens-chip-on', parseInt($(this).data('days'), 10) === talkDays);
            });
            ajaxGet('/api/lens/devices/destinations', { mac: macs(), days: talkDays }, (report, status) => {
                const $rows = $('#dvTalkRows').empty();
                if (status !== 'success' || !report || report.rows === undefined) {
                    $('#dvTalkNote').text('{{ lang._("Destinations did not come back.") }}');
                    return;
                }
                $('#dvTalkNote').text(report.note || '');
                $('#dvTalkSettings').toggle(!report.enabled);
                $('#dvTalkTable').toggle(report.rows.length > 0);
                for (const row of report.rows) {
                    const $tr = $('<tr/>')
                        .append($('<td/>').addClass('dv-peer').text(row.peer)
                            .append($('<div/>').addClass('dv-sub dv-peer-svc').text(row.service)))
                        .append($('<td/>').addClass('dv-sub').text(row.service))
                        .append($('<td/>').addClass('dv-talk-bar').append(
                            $('<div/>').addClass('lens-track').append(
                                $('<div/>').addClass('lens-fill').css('width', Math.max(2, row.bar) + '%'))))
                        .append($('<td/>').addClass('dv-num-cell').text(row.traffic))
                        .append($('<td/>').addClass('dv-sub dv-num-cell').text(
                            row.days + ' {{ lang._("days") }}'));
                    Lens.tip($tr[0], [
                        { label: row.peer + ' \u00b7 ' + row.service },
                        { value: row.sent_text, label: '{{ lang._("sent") }}', key: 'var(--lens-sent)' },
                        { value: row.received_text, label: '{{ lang._("received") }}', key: 'var(--lens-received)' },
                        { label: row.share_text + ' {{ lang._("of everything it moved in this range") }}' },
                    ]);
                    $rows.append($tr);
                }
                if (report.other) {
                    $rows.append($('<tr/>')
                        .append($('<td/>').addClass('dv-sub').text('{{ lang._("everything else") }}'))
                        .append($('<td/>')).append($('<td/>'))
                        .append($('<td/>').addClass('dv-num-cell dv-sub').text(report.other.traffic))
                        .append($('<td/>')));
                }
            });
        };
        /* ------------------------------------------------ what it looked up (§4.64) */
        let dnsHours = 24;
        const looked = () => {
            $('#dvDnsRange .lens-chip').each(function () {
                $(this).toggleClass('lens-chip-on', parseInt($(this).data('hours'), 10) === dnsHours);
            });
            $('#dvDnsAsk').hide();
            $('#dvDnsBusy').show();
            $.ajax({ url: '/api/lens/dns/device', data: { mac: macs(), hours: dnsHours }, dataType: 'json' })
                .done((report) => {
                    $('#dvDnsBusy').hide();
                    const $rows = $('#dvDnsRows').empty();
                    $('#dvDnsSummary').text(report.summary || '');
                    $('#dvDnsNote').text(report.note || '');
                    $('#dvDnsSettings').toggle(!!report.settings);
                    /* its week of questions, local time (§4.70) */
                    $('#dvDnsWeek').toggle(!!report.heatmap);
                    if (report.heatmap) {
                        drawGrid(report.heatmap, $('#dvDnsHeatmap'));
                        $('#dvDnsHeatmapNote').text(report.heatmap.empty ? ''
                            : '{{ lang._("Questions per hour of the week, local time. Busiest:") }} ' + report.heatmap.peak
                              + (report.heatmap.busiest ? ' \u00b7 ' + report.heatmap.days[report.heatmap.busiest.day]
                                 + ' ' + report.heatmap.busiest.hour + ':00' : ''));
                    }
                    /* which services, from the same questions (#44) */
                    const $services = $('#dvDnsServices').empty();
                    if ((report.services || []).length) {
                        $services.append(Lens.services(report.services))
                            .append($('<div/>').addClass('dv-sub').text(report.services_limits || ''));
                    }
                    $services.toggle(!!(report.services || []).length);
                    for (const row of report.rows || []) {
                        const $name = $('<div/>').addClass('dns-name').text(row.domain);
                        if (row.blocked) {
                            $name.append($('<span/>').addClass('dns-flag')
                                .text('{{ lang._("blocked") }}' + (row.blocklist ? ' · ' + row.blocklist : '')));
                        }
                        const $row = $('<div/>').addClass('dns-row')
                            .append($name)
                            .append($('<div/>').addClass('lens-track').append(
                                $('<div/>').addClass('lens-fill').css('width', Math.max(2, row.bar) + '%')))
                            .append($('<div/>').addClass('dv-num-cell').text(row.count));
                        if (row.last) {
                            Lens.tip($row[0], [{ label: row.domain },
                                               { label: '{{ lang._("last asked") }} ' + Lens.when(row.last, 60, true) }]);
                        }
                        $rows.append($row);
                    }
                })
                .fail((xhr) => {
                    $('#dvDnsBusy').hide();
                    $('#dvDnsNote').text(xhr.status === 403
                        ? '{{ lang._("Seeing this needs the Reporting: Lens: DNS privilege.") }}'
                        : '{{ lang._("Unbound did not answer.") }}');
                });
        };
        $('#dvDnsAsk').on('click', (event) => {
            event.preventDefault();
            looked();
        });
        $('#dvDnsRange').on('click', '.lens-chip', function (event) {
            event.preventDefault();
            dnsHours = parseInt($(this).data('hours'), 10);
            looked();
        });

        $('#dvTalkRange').on('click', '.lens-chip', function (event) {
            event.preventDefault();
            talkDays = parseInt($(this).data('days'), 10);
            talks();
        });

        /* ------------------------------------------------ editing, in place */
        $('#dvEdit').on('click', (event) => {
            event.preventDefault();
            $('#dvEditName').val(device.label.name);
            $('#dvEditTags').val(device.label.tags);
            $('#dvEditNote').val(device.label.note);
            $('#dvEditOwner').val(device.label.owner || '');
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
                tags: $('#dvEditTags').val(), note: $('#dvEditNote').val(), owner: $('#dvEditOwner').val()
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

        /* the mute (§4.63): every MAC of a folded phone, so a rotation stays quiet */
        $('#dvMute').on('click', (event) => {
            event.preventDefault();
            ajaxCall('/api/lens/devices/mute', { mac: macs(), muted: device.muted ? '0' : '1' }, (reply, status) => {
                if (status === 'success' && reply && reply.status === 'ok') {
                    load();
                }
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
        <div class="dv-hero-edit">
            <a href="#" id="dvMute" class="btn btn-default btn-sm"></a>
            <a href="#" id="dvEdit" class="btn btn-default btn-sm">
                <i class="fa fa-pencil"></i> {{ lang._('Name it') }}
            </a>
            <a href="/ui/lens/compare" id="dvCompare" class="btn btn-default btn-sm"
               title="{{ lang._('Put this device next to others') }}">
                <i class="fa fa-columns"></i> {{ lang._('Compare') }}
            </a>
            <a href="/ui/lens/privacy" id="dvForget" class="btn btn-default btn-sm"
               title="{{ lang._('Delete everything Lens holds about this device') }}">
                <i class="fa fa-trash"></i> {{ lang._('Forget...') }}
            </a>
            <a href="#" id="dvPause" class="btn btn-default btn-sm" style="display: none;"
               title="{{ lang._('Take this device off the internet for a while') }}">
                <i class="fa fa-pause"></i> {{ lang._('Pause...') }}
            </a>
        </div>
    </div>

    <div id="dvPaused" class="alert alert-warning dv-paused" style="display: none;">
        <i class="fa fa-pause-circle"></i> <span id="dvPausedText"></span>
        <button id="dvResume" class="btn btn-default btn-sm" style="display: none;">
            <i class="fa fa-play"></i> {{ lang._('Resume') }}
        </button>
    </div>

    <div id="dvPauseBox" class="content-box dv-card" style="display: none; margin-bottom: 14px;">
        <div class="dv-sub" style="margin-bottom: 8px;">{{ lang._('Pause this device') }}</div>
        <div id="dvPauseRefused" class="alert alert-info" style="display: none;"></div>
        <div id="dvPauseForm">
            <div class="dv-pause-for" id="dvPauseFor">
                <a href="#" class="lens-chip" data-minutes="30">{{ lang._('30 minutes') }}</a>
                <a href="#" class="lens-chip lens-chip-on" data-minutes="60">{{ lang._('1 hour') }}</a>
                <a href="#" class="lens-chip" data-minutes="120">{{ lang._('2 hours') }}</a>
                <a href="#" class="lens-chip" data-minutes="morning">{{ lang._('until 06:00') }}</a>
                <a href="#" class="lens-chip" data-minutes="pick">{{ lang._('until...') }}</a>
                <a href="#" class="lens-chip" data-minutes="0">{{ lang._('until I resume it') }}</a>
            </div>
            <div id="dvPauseUntilBox" class="dv-pause-until" style="display: none;">
                <input type="datetime-local" id="dvPauseUntil" class="form-control input-sm">
                <span id="dvPauseUntilNote" class="dv-sub"></span>
            </div>
            <div class="dv-pause-reason">
                <input type="text" id="dvPauseReason" class="form-control input-sm" maxlength="60"
                       placeholder="{{ lang._('Why? (optional, e.g. homework, bedtime) - shown on Events and in the alias') }}">
            </div>
            <div id="dvPauseUnguarded" class="alert alert-info" style="display: none;">
                {{ lang._('No network is protected yet, only the one you are clicking from. Tick your management networks under Services: Lens: Settings, Pausing a device.') }}
            </div>
            <ul class="dv-sub dv-pause-limits">
                <li>{{ lang._('One floating rule, "Lens: paused devices", blocks what this device sends through the firewall: the internet, other networks and the firewall itself. It shows in Firewall: Rules and in the configuration history.') }}</li>
                <li>{{ lang._('It follows the device by its MAC. A phone that switches to a new private address escapes it, unless Lens already shows its addresses as one device.') }}</li>
                <li>{{ lang._('Devices on the same network still reach each other directly. A timed pause ends within five minutes of its time.') }}</li>
            </ul>
            <div id="dvPauseError" class="alert alert-danger" style="display: none;"></div>
            <button id="dvPauseGo" class="btn btn-primary btn-sm"><i class="fa fa-pause"></i> {{ lang._('Pause now') }}</button>
            <a href="#" id="dvPauseCancel" style="margin-left: 10px;">{{ lang._('Cancel') }}</a>
        </div>
    </div>

    <div id="dvEditBox" class="content-box dv-card" style="display: none; margin-bottom: 14px;">
        <div id="dvEditError" class="alert alert-danger" style="display: none;"></div>
        <div class="row">
            <div class="col-md-3"><label>{{ lang._('Name') }}</label>
                <input type="text" id="dvEditName" class="form-control"
                       placeholder="{{ lang._('keep the observed name') }}"></div>
            <div class="col-md-2"><label>{{ lang._('Kind') }}</label>
                <select id="dvEditKind" class="form-control"></select></div>
            <div class="col-md-2"><label>{{ lang._('Belongs to') }}</label>
                <input type="text" id="dvEditOwner" class="form-control"
                       placeholder="{{ lang._('a person') }}"></div>
            <div class="col-md-2"><label>{{ lang._('Tags') }}</label>
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
            <div class="lens-axis-row" id="dvChartAxis"></div>
            <div class="dv-sub" id="dvChartBusy"></div>
            <div id="dvMoment" style="display: none; margin-top: 10px;">
                <b>{{ lang._('That slice was') }}</b> &middot; <span id="dvMomentWhen" class="dv-sub"></span>
                <table class="table table-condensed"><tbody id="dvMomentRows"></tbody></table>
            </div>
        </div>

        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Its week') }}</span></div>
            <div class="hm-scroll"><div class="hm" id="dvHeatmap"></div></div>
            <div class="dv-sub" id="dvHeatmapNote" style="margin-top: 8px;"></div>
        </div>

        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Where it has been') }}</span></div>
            <ul class="dv-story" id="dvStory"></ul>
        </div>

        <div class="content-box dv-card dv-wide">
            <div class="dv-title">
                <span>{{ lang._('Where it talks') }}</span>
                <span id="dvTalkRange">
                    <a href="#" class="lens-chip" data-days="7">{{ lang._('7 days') }}</a>
                    <a href="#" class="lens-chip" data-days="30">{{ lang._('30 days') }}</a>
                    <a href="#" class="lens-chip" data-days="60">{{ lang._('60 days') }}</a>
                </span>
            </div>
            <table class="table table-condensed dv-talk" id="dvTalkTable" style="display: none;">
                <tbody id="dvTalkRows"></tbody>
            </table>
            <div class="dv-sub" id="dvTalkNote"></div>
            <a href="/ui/lens/settings" id="dvTalkSettings" style="display: none;">{{ lang._('Services: Lens: Settings') }} &rsaquo;</a>
        </div>

        <div class="content-box dv-card dv-wide">
            <div class="dv-title">
                <span>{{ lang._('What it looked up') }}</span>
                <span id="dvDnsRange">
                    <a href="#" class="lens-chip" data-hours="24">{{ lang._('24 hours') }}</a>
                    <a href="#" class="lens-chip" data-hours="168">{{ lang._('7 days') }}</a>
                </span>
            </div>
            <a href="#" id="dvDnsAsk" class="btn btn-default btn-sm">
                <i class="fa fa-search"></i> {{ lang._('Ask Unbound') }}
            </a>
            <div id="dvDnsBusy" style="display: none;"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Asking Unbound about each address it held...') }}</div>
            <div class="dv-sub" id="dvDnsSummary" style="margin-bottom: 6px;"></div>
            <div id="dvDnsServices" style="display: none; margin-bottom: 10px;"></div>
            <div id="dvDnsRows"></div>
            <div id="dvDnsWeek" style="display: none; margin-top: 12px;">
                <div class="hm-scroll"><div class="hm" id="dvDnsHeatmap"></div></div>
                <div class="dv-sub" id="dvDnsHeatmapNote"></div>
            </div>
            <div class="lens-note-under" id="dvDnsNote"></div>
            <a href="/ui/lens/settings" id="dvDnsSettings" style="display: none;">{{ lang._('Services: Lens: Settings') }} &rsaquo;</a>
        </div>

        <div class="content-box dv-card dv-wide">
            <div class="dv-title"><span>{{ lang._('Home in the last seven days') }}</span></div>
            <div class="dv-strip" id="dvStrip"></div>
            <div class="dv-sub" id="dvStripNote" style="margin-top: 6px;"></div>
        </div>
    </div>
</div>
