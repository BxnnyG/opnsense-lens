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
    /*
     * The week on one page (§4.67): the pages it summarises, read once each,
     * laid out for A4. Every sentence here is the one its own page says --
     * nothing is judged again in the browser.
     */
    $(document).ready(() => {
        const HOURS = 168;
        const now = new Date();
        const from = new Date(now.getTime() - 7 * 86400000);
        const day = (date) => date.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'long' });
        $('#rpWeek').text(day(from) + ' – ' + day(now));
        $('#rpMade').text('{{ lang._("made") }} ' + now.toLocaleString([], {
            day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }));

        const read = (url, data) => new Promise((resolve) => {
            ajaxGet(url, data, (reply, status) => resolve(status === 'success' ? reply : null));
        });
        const failed = ($box) => $box.append($('<div/>').addClass('text-muted')
            .text('{{ lang._("This part did not come back.") }}'));
        /* numeric: the columns that hold figures, set right like figures */
        const table = (rows, numeric = []) => {
            const $body = $('<tbody/>');
            for (const cells of rows) {
                const $tr = $('<tr/>');
                cells.forEach((cell, index) => $tr.append($('<td/>').text(cell)
                    .toggleClass('dv-num-cell', numeric.includes(index))));
                $body.append($tr);
            }
            return $('<table/>').addClass('table table-condensed rp-table').append($body);
        };

        Promise.all([
            read('/api/lens/devices/list', { hours: HOURS }),
            read('/api/lens/dashboard/internet', { hours: HOURS }),
            read('/api/lens/events/list', { days: 7 }),
            read('/api/lens/devices/presence', { hours: HOURS }),
            read('/api/lens/segments/list', { hours: HOURS }),
        ]).then(([devices, internet, events, presence, segments]) => {
            $('#rpLoading').hide();

            /* the one sentence */
            if (devices && devices.sentence) {
                $('#rpSentence').text(devices.sentence.sentence);
                $('#rpAlso').text((devices.sentence.also || []).join(' '));
            }

            /* the internet */
            const $net = $('#rpInternet');
            if (internet && internet.uptime) {
                const outages = internet.uptime.outages || [];
                $net.append($('<p/>').addClass('rp-lead').text(internet.uptime.percent !== null
                    ? internet.uptime.percent + '% {{ lang._("of the week the internet answered") }}'
                      + (outages.length === 1 ? ', {{ lang._("and once it did not") }}.'
                          : outages.length ? ', ' + outages.length + ' {{ lang._("times it did not") }}.' : '.')
                    : '{{ lang._("The internet was not measured this week.") }}'));
                if (outages.length) {
                    $net.append(table(outages.map(o => [
                        new Date(o.from * 1000).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }),
                        o.for]), [1]));
                }
            } else {
                failed($net);
            }

            /* traffic, and last week beside it */
            const $traffic = $('#rpTraffic');
            if (devices && devices.summary) {
                const compare = devices.compare || {};
                $traffic.append($('<p/>').addClass('rp-lead').text(devices.summary.moved + ' {{ lang._("attributed to devices") }}'
                    + (compare.covered && compare.total.text ? ', ' + compare.total.text + ' ' + compare.label : '')
                    + '.'));
                $traffic.append(table(devices.devices.filter(d => d.octets > 0).slice(0, 10).map(d => [
                    d.name, (d.traffic || '').split('  ')[0], d.compare && d.compare.text ? d.compare.text : '']), [1, 2]));
                if (devices.baseline && devices.baseline.unusual && devices.baseline.unusual.length) {
                    $traffic.append($('<p/>').text(devices.baseline.headline));
                }
            } else {
                failed($traffic);
            }

            /* what happened */
            const $events = $('#rpEvents');
            if (events && events.events) {
                $events.append($('<p/>').addClass('rp-lead').text(events.headline));
                const shown = events.events.filter(e => !e.muted).slice(0, 15);
                if (shown.length) {
                    $events.append(table(shown.map(e => [
                        new Date(e.at * 1000).toLocaleDateString([], { weekday: 'short', day: 'numeric' }),
                        e.title])));
                }
            } else {
                failed($events);
            }

            /* who was home */
            const $who = $('#rpWho');
            if (presence && presence.people) {
                if (presence.people.length) {
                    $who.append(table(presence.people.map(p => [p.name, p.basis, p.present]), [2]));
                } else {
                    $who.append($('<p/>').addClass('text-muted').text(
                        '{{ lang._("No device has an owner yet - Belongs to, when you name one.") }}'));
                }
            } else {
                failed($who);
            }

            /* the networks */
            const $segs = $('#rpNetworks');
            if (segments && segments.segments) {
                $segs.append(table(segments.segments.filter(s => s.is_network).map(s => [s.name, s.traffic]), [1]));
            } else {
                failed($segs);
            }

            $('#rpBody').show();
        });

        $('#rpPrint').on('click', (event) => {
            event.preventDefault();
            window.print();
        });
    });
</script>

<div class="who-head rp-head">
    <div>
        <div class="rp-title">{{ lang._('The week on this network') }}</div>
        <div class="dv-sub"><span id="rpWeek"></span> &middot; <span id="rpMade"></span></div>
    </div>
    <div class="rp-noprint">
        <a href="#" id="rpPrint" class="btn btn-default btn-sm"><i class="fa fa-print"></i> {{ lang._('Print or save as PDF') }}</a>
    </div>
</div>

<div id="rpLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading the week...') }}</div>

<div id="rpBody" style="display: none;">
    <div class="content-box lens-box rp-section">
        <div class="ev-headline" id="rpSentence"></div>
        <div class="dv-sub" id="rpAlso"></div>
    </div>
    <div class="rp-grid">
        <div class="content-box lens-box rp-section">
            <div class="dv-title"><span>{{ lang._('The internet') }}</span></div>
            <div id="rpInternet"></div>
        </div>
        <div class="content-box lens-box rp-section">
            <div class="dv-title"><span>{{ lang._('Who was home') }}</span></div>
            <div id="rpWho"></div>
        </div>
    </div>
    <div class="content-box lens-box rp-section">
        <div class="dv-title"><span>{{ lang._('Traffic, heaviest devices') }}</span></div>
        <div id="rpTraffic"></div>
    </div>
    <div class="content-box lens-box rp-section">
        <div class="dv-title"><span>{{ lang._('What happened') }}</span></div>
        <div id="rpEvents"></div>
    </div>
    <div class="content-box lens-box rp-section">
        <div class="dv-title"><span>{{ lang._('Networks') }}</span></div>
        <div id="rpNetworks"></div>
    </div>
    <div class="lens-note-under">{{ lang._('Every line here is the one its own page says; Lens reads OPNsense\'s data and keeps its own, and nothing on this page left the firewall.') }}</div>
</div>
