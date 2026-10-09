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

        const $range = $('#whoRange');
        for (const h of [24, 168, 720]) {
            $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', h === hours)
                .attr('href', location.pathname + '?hours=' + h).text(LABELS[h])
                .appendTo($range);
        }

        /* ticks every few hours for a day, every day for a week, weekly beyond */
        const ticks = (start, now) => {
            const span = now - start;
            const step = span <= 2 * 86400 ? 4 * 3600 : span <= 10 * 86400 ? 86400 : 7 * 86400;
            const $ticks = $('<div/>').addClass('who-ticks');
            let at = Math.ceil(start / step) * step;
            for (; at < now; at += step) {
                const date = new Date(at * 1000);
                const text = step < 86400
                    ? Lens.time(date.getTime() / 1000)
                    : date.toLocaleDateString([], { day: 'numeric', month: 'short' });
                $('<span/>').addClass('who-tick')
                    .css('left', ((at - start) / span * 100) + '%').text(text)
                    .appendTo($ticks);
            }
            return $('<div/>').addClass('who-axis')
                .append($('<div/>').addClass('who-name'))
                .append($ticks)
                .append($('<div/>').addClass('who-meta'));
        };

        const row = (entry, start, now) => {
            const span = Math.max(1, now - start);
            const $track = $('<div/>').addClass('who-track');
            for (const [from, to] of entry.spans) {
                const $span = $('<div/>').addClass('who-span').css({
                    left: ((from - start) / span * 100) + '%',
                    width: ((to - from) / span * 100) + '%'
                }).appendTo($track);
                Lens.tip($span[0], [{ value: entry.name },
                                    { label: Lens.when(from, 3600, true) + ' \u2013 ' + Lens.when(to, 3600, true) }]);
            }

            /* every strip opens its device: when, how much, with whom (operator, 2026-10-07) */
            const link = entry.mac ? '/ui/lens/device?mac=' + encodeURIComponent(entry.mac) : null;
            const $label = link ? $('<a/>').attr('href', link) : $('<span/>');
            $label.append($('<i/>').addClass('fa ' + entry.icon)).append(document.createTextNode(' ' + entry.name));
            const $name = $('<div/>').addClass('who-name').attr('title', entry.name).append($label);
            if (link) {
                $track.addClass('who-track-link').on('click', () => {
                    location.href = link;
                });
            }
            if (entry.here) {
                $name.append($('<span/>').addClass('who-dot')
                    .attr('title', '{{ lang._("here now") }}'));
            }

            return $('<div/>').addClass('who-row').toggleClass('here', entry.here)
                .append($name)
                .append($track)
                .append($('<div/>').addClass('who-meta').text(entry.present));
        };

        let report = null;

        /* one strip per person, from the devices they carry (§4.67) */
        const person = (entry, start, now) => {
            const $row = row({ name: entry.name, icon: 'fa-user', here: entry.here, spans: entry.spans,
                               present: entry.present }, start, now);
            const $devices = $('<div/>').addClass('who-basis');
            entry.devices.forEach((device, i) => {
                $devices.append(i ? ', ' : '').append(device.mac
                    ? $('<a/>').attr('href', '/ui/lens/device?mac=' + encodeURIComponent(device.mac)).text(device.name)
                    : document.createTextNode(device.name));
            });
            $row.find('.who-name').append($('<div/>').addClass('who-basis').text(entry.basis)).append($devices)
                .attr('title', entry.devices.map(d => d.name).join(', '));
            return $row;
        };

        /* drawn again when the filter bar changes (§4.65) */
        const draw = () => {
            const people = (report.people || []).filter(entry => Lens.filter.matches(entry));
            const $people = $('#whoPeople').empty().append(ticks(report.start, report.now));
            for (const entry of people) {
                $people.append(person(entry, report.start, report.now));
            }
            $('#whoPeopleBox').toggle(people.length > 0);
            $('#whoPeopleHint').toggle(!(report.people || []).length);

            const moving = report.moving.filter(entry => Lens.filter.matches(entry));
            const always = report.always.filter(entry => Lens.filter.matches(entry));

            const $moving = $('#whoMoving').empty().append(ticks(report.start, report.now));
            for (const entry of moving) {
                $moving.append(row(entry, report.start, report.now));
            }
            if (!moving.length) {
                $moving.append($('<div/>').addClass('text-muted').text(Lens.filter.active()
                    ? '{{ lang._("Nothing on the chosen networks and tags came and went.") }}'
                    : '{{ lang._("Every device was here the whole time.") }}'));
            }

            const $always = $('#whoAlways').empty().append(ticks(report.start, report.now));
            for (const entry of always) {
                $always.append(row(entry, report.start, report.now));
            }
            $('#whoAlwaysCount').text(always.length);
            $('#whoAlwaysBox').toggle(always.length > 0);
        };

        Lens.filter.mount(document.getElementById('whoNote'), () => {
            if (report) {
                draw();
            }
        });

        ajaxGet('/api/lens/devices/presence', { hours: hours }, (reply, status) => {
            $('#whoLoading').hide();
            if (status !== 'success' || !reply || !reply.moving) {
                $('#whoError').show();
                return;
            }

            report = reply;
            $('#whoNote').toggle(!!report.note).text(report.note || '');
            draw();
            $('#whoReport').show();
        });

        $('#whoAlwaysToggle').on('click', function (event) {
            event.preventDefault();
            $('#whoAlways').toggle();
            $(this).text($('#whoAlways').is(':visible')
                ? '{{ lang._("hide them") }}' : '{{ lang._("show them") }}');
        });
    });
</script>

<div class="who-head">
    <div>{{ lang._('When each device was on the network. Nothing in OPNsense keeps this; the collector has since the day it was installed.') }}</div>
    <div id="whoRange"></div>
</div>
<div id="whoNote" class="text-muted" style="display: none; margin-bottom: 10px;"></div>

<div id="whoLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading who was here...') }}</div>
<div id="whoError" class="alert alert-danger" style="display: none;">
    {{ lang._('The presence report did not come back.') }}
</div>

<div id="whoReport" style="display: none;">
    <div id="whoPeopleBox" class="content-box who-box" style="display: none;">
        <div class="who-title">{{ lang._('By person') }}</div>
        <div id="whoPeople"></div>
    </div>
    <div id="whoPeopleHint" class="lens-note-under" style="display: none; margin: 0 0 10px 0;">
        {{ lang._('Say whose a device is - Belongs to, when you name it - and this page draws one strip per person, from the phones they carry.') }}
    </div>

    <div class="content-box who-box">
        <div class="who-title">{{ lang._('Came and went') }}</div>
        <div id="whoMoving"></div>
    </div>

    <div id="whoAlwaysBox" class="content-box who-box">
        <div class="who-title">
            <span id="whoAlwaysCount"></span> {{ lang._('were here the whole time') }}
            &mdash; <a href="#" id="whoAlwaysToggle">{{ lang._('show them') }}</a>
        </div>
        <div id="whoAlways" style="display: none;"></div>
        <div class="text-muted" style="font-size: 12px;">
            {{ lang._('Servers, access points and virtual machines, usually. They are folded away because forty solid bars say nothing - measured by how much of the range each was present for, not guessed from what kind of device it is.') }}
        </div>
    </div>
</div>
