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
        const RANGES = { 1: '{{ lang._("24 hours") }}', 7: '{{ lang._("7 days") }}', 30: '{{ lang._("30 days") }}' };
        const GROUPS = { all: '{{ lang._("Everything") }}', devices: '{{ lang._("Devices") }}',
                         internet: '{{ lang._("Internet") }}', identity: '{{ lang._("Identity") }}' };
        const query = new URLSearchParams(location.search);
        const days = RANGES[parseInt(query.get('days'), 10)] ? parseInt(query.get('days'), 10) : 7;
        let group = GROUPS[query.get('kind')] ? query.get('kind') : 'all';
        let showMuted = false;
        let report = null;

        const $range = $('#evRange');
        for (const d of [1, 7, 30]) {
            $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', d === days)
                .attr('href', location.pathname + '?days=' + d + (group !== 'all' ? '&kind=' + group : ''))
                .text(RANGES[d]).appendTo($range);
        }

        /* "Today", "Yesterday", or the date the way this browser writes one */
        const dayLabel = (at) => {
            const date = new Date(at * 1000);
            const start = new Date();
            start.setHours(0, 0, 0, 0);
            const diff = Math.round((start - new Date(date).setHours(0, 0, 0, 0)) / 86400000);
            if (diff === 0) {
                return '{{ lang._("Today") }}';
            }
            if (diff === 1) {
                return '{{ lang._("Yesterday") }}';
            }
            return date.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
        };

        const mute = (event, muted) => {
            ajaxCall('/api/lens/devices/mute', { mac: event.device.macs.join(','), muted: muted ? '1' : '0' },
                (reply, status) => {
                    if (status === 'success' && reply && reply.status === 'ok') {
                        load();
                    }
                });
        };

        const row = (event) => {
            const $row = $('<div/>').addClass('ev-row ev-' + event.tone).toggleClass('ev-muted', event.muted);
            const time = event.grain === 'day'
                ? '{{ lang._("all day") }}'
                : Lens.time(event.at);
            $row.append($('<div/>').addClass('ev-time').text(time));
            $row.append($('<div/>').addClass('ev-word').text(event.word));
            const $body = $('<div/>').addClass('ev-body')
                .append($('<a/>').addClass('ev-title').attr('href', event.link).text(event.title))
                .append($('<div/>').addClass('dv-sub').text(event.detail));
            $row.append($body);
            if (event.device) {
                $row.append($('<a/>').attr('href', '#').addClass('ev-mute')
                    .attr('title', event.device.muted
                        ? '{{ lang._("Unmute this device") }}'
                        : '{{ lang._("Mute this device: its events fold away, its figures stay") }}')
                    .append($('<i/>').addClass('fa ' + (event.device.muted ? 'fa-bell' : 'fa-bell-slash')))
                    .on('click', (e) => {
                        e.preventDefault();
                        mute(event, !event.device.muted);
                    }));
            }
            return $row;
        };

        const draw = () => {
            const $groups = $('#evGroups').empty();
            for (const key of Object.keys(GROUPS)) {
                $('<a/>').attr('href', '#').addClass('lens-chip').toggleClass('lens-chip-on', key === group)
                    .text(GROUPS[key] + ' ' + report.counts[key]).data('group', key).appendTo($groups);
            }

            const $list = $('#evList').empty();
            let day = null;
            let shown = 0;
            for (const event of report.events) {
                if ((group !== 'all' && event.group !== group) || (event.muted && !showMuted)) {
                    continue;
                }
                /* the line and the internet concern every network: no filter hides them (§4.65) */
                if (!Lens.filter.matches(event.device)) {
                    continue;
                }
                const label = dayLabel(event.at);
                if (label !== day) {
                    day = label;
                    $list.append($('<div/>').addClass('ev-day').text(label));
                }
                $list.append(row(event));
                shown++;
            }
            if (!shown) {
                $list.append($('<div/>').addClass('text-muted ev-empty')
                    .text('{{ lang._("Nothing of this kind in this range.") }}'));
            }

            $('#evMutedBox').toggle(report.muted > 0);
            $('#evMutedCount').text(report.muted);
            $('#evMutedToggle').text(showMuted ? '{{ lang._("hide them") }}' : '{{ lang._("show them") }}');
        };

        const load = () => {
            ajaxGet('/api/lens/events/list', { days: days }, (reply, status) => {
                $('#evLoading').hide();
                if (status !== 'success' || !reply || !reply.events) {
                    $('#evError').show();
                    return;
                }
                report = reply;
                $('#evHeadline').text(report.headline);
                const $notes = $('#evNotes').empty();
                for (const note of report.notes) {
                    $notes.append($('<div/>').text(note));
                }
                draw();
                $('#evReport').show();
            });
        };

        $('#evGroups').on('click', '.lens-chip', function (event) {
            event.preventDefault();
            group = $(this).data('group');
            const url = new URL(location.href);
            if (group === 'all') {
                url.searchParams.delete('kind');
            } else {
                url.searchParams.set('kind', group);
            }
            history.replaceState(null, '', url);
            draw();
        });
        $('#evMutedToggle').on('click', (event) => {
            event.preventDefault();
            showMuted = !showMuted;
            draw();
        });

        Lens.filter.mount(document.getElementById('evLoading'), () => {
            if (report) {
                draw();
            }
        });
        load();
    });
</script>

<div class="who-head">
    <div>{{ lang._('What happened while you were not looking: new devices, unusual days, the internet and the line, and addresses that changed hands. Each opens the page that proves it.') }}</div>
    <div id="evRange"></div>
</div>

<div id="evLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading what happened...') }}</div>
<div id="evError" class="alert alert-danger" style="display: none;">
    {{ lang._('The events did not come back.') }}
</div>

<div id="evReport" style="display: none;">
    <div class="content-box lens-box">
        <div class="ev-headline" id="evHeadline"></div>
        <div id="evGroups" class="ev-groups"></div>
        <div id="evList"></div>
        <div id="evMutedBox" class="lens-note-under" style="display: none;">
            <span id="evMutedCount"></span> {{ lang._('from devices you muted') }} &mdash;
            <a href="#" id="evMutedToggle"></a>
        </div>
        <div id="evNotes" class="lens-note-under"></div>
    </div>
</div>
