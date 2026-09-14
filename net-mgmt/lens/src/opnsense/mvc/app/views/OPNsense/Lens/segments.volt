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
    {{ lang._('How much each of your networks carried, and how much of it Lens can put a device to.') }}
    <a href="/ui/lens/overview">{{ lang._('The devices themselves are one page over.') }}</a>
</p>

<style>
    .seg-bar { display: block; height: 16px; background: rgba(128,128,128,0.2);
               border-radius: 3px; overflow: hidden; min-width: 2px; }
    .seg-named { display: block; height: 16px; background: #d94f00; opacity: 0.75; }
    .seg-name { font-weight: 600; }
    .seg-if { display: block; color: #999; font-size: 90%; font-family: monospace; }
    .seg-note { display: block; color: #999; font-size: 90%; max-width: 34em; }
    .seg-thin { color: #f0ad4e; }
    .seg-num { text-align: right; white-space: nowrap; }
    #lensRangeWrap { margin: 10px 0; }
    #lensRangeNote { margin-left: 10px; }
    .lens-chip { display: inline-block; padding: 1px 8px; margin: 2px 3px;
                 border: 1px solid #999; border-radius: 10px; font-size: 90%;
                 text-decoration: none; }
    .lens-chip-on { border-color: #d94f00; color: #d94f00; font-weight: 600; }
</style>

<script>
    $(document).ready(() => {
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

        const pct = (share) => Math.round(share * 100) + '%';

        ajaxGet('/api/lens/segments/list', { hours: chosenHours() }, (report, status) => {
            $('#segLoading').hide();

            if (status !== 'success' || !report || !report.segments) {
                $('#segError').show();
                return;
            }

            if (!report.segments.length) {
                $('#segEmpty').show();
                return;
            }

            $('#segTotal').text(report.total);
            $('#segHours').text((report.window || {}).asked || '');
            drawRange(report.window || {});

            const largest = report.segments.reduce((max, s) => Math.max(max, s.octets), 0);
            const $body = $('#segTable > tbody').empty();

            for (const segment of report.segments) {
                const $name = $('<td/>')
                    .append($('<span/>').addClass('seg-name').text(segment.name))
                    .append($('<span/>').addClass('seg-if').text(segment.interface));
                if (segment.note) {
                    $name.append($('<span/>').addClass('seg-note').text(segment.note));
                }

                /* one bar, two meanings: its length is how much this segment
                   carried, the filled part is how much of it has a device */
                const $bar = $('<span/>').addClass('seg-bar')
                    .css('width', largest ? Math.max(2, (segment.octets / largest) * 100) + '%' : '0')
                    .append($('<span/>').addClass('seg-named')
                        .css('width', pct(segment.named_share)));

                const $named = $('<td/>').addClass('seg-num')
                    .text(pct(segment.named_share));
                if (segment.is_network && segment.named_share < 0.5) {
                    $named.addClass('seg-thin');
                }
                if (!segment.is_network) {
                    $named.text('—');
                }

                $body.append($('<tr/>')
                    .append($name)
                    .append($('<td/>').append($bar))
                    .append($('<td/>').addClass('seg-num').text(segment.traffic))
                    .append($named)
                    .append($('<td/>').addClass('seg-num').text(segment.addresses || '')));
            }

            $('#segReport').show();
        });
    });
</script>

    <div id="lensRangeWrap">
        <span id="lensRange" style="display: none;"></span>
        <span id="lensRangeNote" class="text-muted" style="display: none;"></span>
    </div>

<div id="segLoading">
    <i class="fa fa-spinner fa-spin"></i> {{ lang._('Adding up the segments...') }}
</div>

<div id="segError" class="alert alert-danger" style="display: none;">
    {{ lang._('The segment totals did not come back.') }}
</div>

<div id="segEmpty" class="alert alert-info" style="display: none;">
    {{ lang._('No traffic has been harvested yet. The collector copies it out of NetFlow every half hour.') }}
</div>

<div id="segReport" style="display: none;">
    <p>
        <b id="segTotal"></b> {{ lang._('over') }} <span id="segHours"></span>,
        {{ lang._('across every interface NetFlow reported.') }}
    </p>

    <table id="segTable" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Network') }}</th>
                <th style="width: 30%;">{{ lang._('Share, and how much has a device') }}</th>
                <th class="seg-num">{{ lang._('Traffic') }}</th>
                <th class="seg-num">{{ lang._('Named') }}</th>
                <th class="seg-num">{{ lang._('Addresses') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <p class="text-muted">
        {{ lang._('The bar length is what the segment carried; the filled part is what Lens could attribute to a device on it. A segment that is mostly unfilled is not a busy segment - it is one whose traffic belongs to machines that are not attached to it, and a plain byte total cannot tell those apart.') }}
    </p>
</div>
