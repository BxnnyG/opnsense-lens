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
    .who-head { display: flex; flex-wrap: wrap; gap: 14px; align-items: center;
                justify-content: space-between; margin-bottom: 12px; }
    .who-box { padding: 14px 16px; margin-bottom: 14px; }
    .who-title { font-size: 11px; font-weight: 600; letter-spacing: 0.06em;
                 text-transform: uppercase; color: #999; margin: 0 0 10px 0; }

    .who-axis, .who-row { display: flex; align-items: center; gap: 12px; }
    .who-axis { margin-bottom: 4px; }
    .who-name { flex: 0 0 15em; overflow: hidden; text-overflow: ellipsis;
                white-space: nowrap; }
    .who-name i { width: 18px; text-align: center; opacity: 0.8; }
    .who-track { flex: 1; position: relative; height: 16px; border-radius: 3px;
                 background: rgba(128, 128, 128, 0.12); }
    .who-span { position: absolute; top: 0; height: 16px; border-radius: 3px;
                background: #d94f00; opacity: 0.8; min-width: 2px; }
    .who-row.here .who-name { font-weight: 600; }
    .who-meta { flex: 0 0 8em; text-align: right; color: #999; font-size: 12px;
                white-space: nowrap; }
    .who-row { padding: 3px 0; }
    .who-ticks { flex: 1; position: relative; height: 14px; }
    .who-tick { position: absolute; top: 0; font-size: 10px; color: #999;
                transform: translateX(-50%); white-space: nowrap; }
    .who-tick:first-child { transform: none; }
    .who-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
               background: #5cb85c; margin-left: 6px; vertical-align: middle; }

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
                const when = new Date(from * 1000).toLocaleString() + ' – '
                    + new Date(to * 1000).toLocaleString();
                $('<div/>').addClass('who-span').attr('title', when).css({
                    left: ((from - start) / span * 100) + '%',
                    width: ((to - from) / span * 100) + '%'
                }).appendTo($track);
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
