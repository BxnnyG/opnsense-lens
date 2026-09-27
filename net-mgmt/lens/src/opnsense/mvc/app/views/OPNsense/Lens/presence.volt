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
                    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
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

            const $name = $('<div/>').addClass('who-name').attr('title', entry.name)
                .append($('<i/>').addClass('fa ' + entry.icon))
                .append(document.createTextNode(' ' + entry.name));
            if (entry.here) {
                $name.append($('<span/>').addClass('who-dot')
                    .attr('title', '{{ lang._("here now") }}'));
            }

            return $('<div/>').addClass('who-row').toggleClass('here', entry.here)
                .append($name)
                .append($track)
                .append($('<div/>').addClass('who-meta').text(entry.present));
        };

        ajaxGet('/api/lens/devices/presence', { hours: hours }, (report, status) => {
            $('#whoLoading').hide();
            if (status !== 'success' || !report || !report.moving) {
                $('#whoError').show();
                return;
            }

            $('#whoNote').toggle(!!report.note).text(report.note || '');

            const $moving = $('#whoMoving').empty().append(ticks(report.start, report.now));
            for (const entry of report.moving) {
                $moving.append(row(entry, report.start, report.now));
            }
            if (!report.moving.length) {
                $moving.append($('<div/>').addClass('text-muted')
                    .text('{{ lang._("Every device was here the whole time.") }}'));
            }

            const $always = $('#whoAlways').empty().append(ticks(report.start, report.now));
            for (const entry of report.always) {
                $always.append(row(entry, report.start, report.now));
            }
            $('#whoAlwaysCount').text(report.always.length);
            $('#whoAlwaysBox').toggle(report.always.length > 0);

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
