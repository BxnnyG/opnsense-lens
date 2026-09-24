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

<div class="lens-page-head">
    <div class="lens-page-intro">
        {{ lang._('How much each of your networks carried, and how much of it Lens can put a device to.') }}
        <a href="/ui/lens/overview">{{ lang._('The devices themselves are one page over.') }}</a>
    </div>
    <div class="lens-page-range">
        <span id="lensRange" style="display: none;"></span>
        <a href="#" id="segExport" style="display: none; margin-left: 10px;">
            <i class="fa fa-download"></i> {{ lang._('CSV') }}
        </a>
    </div>
</div>
<div id="lensRangeNote" class="text-muted lens-range-note" style="display: none;"></div>

<style>
    .seg-bar { display: block; height: 16px; background: rgba(128,128,128,0.2);
               border-radius: 3px; overflow: hidden; min-width: 2px; }
    .seg-named { display: block; height: 16px; background: #d94f00; opacity: 0.75; }
    .seg-name { font-weight: 600; }
    .seg-if { display: block; color: #999; font-size: 90%; font-family: monospace; }
    .seg-note { display: block; color: #999; font-size: 90%; max-width: 34em; }
    .seg-thin { color: #f0ad4e; }
    .seg-num { text-align: right; white-space: nowrap; }
    .seg-cards { display: grid; gap: 12px;
                 grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); }
    .seg-card { display: block; padding: 14px 16px; margin: 0; color: inherit;
                text-decoration: none; border: 1px solid rgba(128, 128, 128, 0.18);
                border-radius: 4px; transition: border-color 0.15s; }
    .seg-card:hover { border-color: #d94f00; color: inherit; text-decoration: none; }
    .seg-card-head { display: flex; justify-content: space-between; align-items: flex-start; }
    .seg-card-name { font-size: 16px; font-weight: 600; }
    .seg-card-num { font-size: 26px; font-weight: 600; margin-top: 8px;
                    font-variant-numeric: tabular-nums; }
    .seg-spark { width: 100%; height: 40px; display: block; margin-top: 8px; }
    .seg-area { fill: rgba(217, 79, 0, 0.18); }
    .seg-line { fill: none; stroke: #d94f00; stroke-width: 1.5; vector-effect: non-scaling-stroke; }
    .seg-ring { width: 44px; height: 44px; }
    .seg-ring-bg { fill: none; stroke: rgba(128, 128, 128, 0.2); stroke-width: 3.5; }
    .seg-ring-fg { fill: none; stroke: #5cb85c; stroke-width: 3.5; stroke-linecap: round; }
    .seg-ring-fg.thin { stroke: #f0ad4e; }
    .seg-ring-text { font-size: 8px; text-anchor: middle; fill: currentColor; }
    .lens-page-head { display: flex; flex-wrap: wrap; gap: 16px;
                      align-items: baseline; justify-content: space-between;
                      margin-bottom: 4px; }
    .lens-page-intro { max-width: 60em; }
    .lens-range-note { margin-bottom: 8px; display: block; }
    .lens-box { padding: 14px 16px; margin-bottom: 14px; }
    .lens-box-head { margin: 0 0 10px 0; }
    .lens-box-foot { margin: 10px 0 0 0; font-size: 90%; }
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

        /* same rule as the device list: the file is what the page shows */
        const download = (rows, name) => {
            const quote = (value) => '"' + String(value === undefined || value === null
                ? '' : value).replace(/"/g, '""') + '"';
            const blob = new Blob(
                ['\ufeff' + rows.map(row => row.map(quote).join(',')).join('\r\n')],
                { type: 'text/csv;charset=utf-8' }
            );
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');

            link.href = url;
            link.download = name;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        };

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

            /*
             * Your networks as cards, the way UniFi shows them: a number, a
             * sparkline, and a ring for how much of it has a device on it. What
             * is not yours -- the far side of the line, lo0, the unplaced flows --
             * is a short list underneath, because it is context, not a network
             * you can open.
             */
            const mine = report.segments.filter(seg => seg.is_network);
            const other = report.segments.filter(seg => !seg.is_network);

            const svgNode = (tag, attrs) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
                for (const [key, value] of Object.entries(attrs || {})) {
                    node.setAttribute(key, value);
                }
                return node;
            };

            const sparkline = (series) => {
                const node = svgNode('svg', { viewBox: '0 0 200 40', preserveAspectRatio: 'none',
                                              class: 'seg-spark' });
                const peak = Math.max(1, ...series);
                if (series.length < 2) {
                    return node;
                }
                const x = (i) => i / (series.length - 1) * 200;
                const y = (v) => 38 - v / peak * 34;
                let line = 'M 0 ' + y(series[0]);
                series.forEach((v, i) => { line += ' L ' + x(i) + ' ' + y(v); });
                node.appendChild(svgNode('path', { d: line + ' L 200 40 L 0 40 Z', class: 'seg-area' }));
                node.appendChild(svgNode('path', { d: line, class: 'seg-line' }));
                return node;
            };

            const ring = (share) => {
                const node = svgNode('svg', { viewBox: '0 0 36 36', class: 'seg-ring' });
                const r = 15.9;
                const c = 2 * Math.PI * r;
                node.appendChild(svgNode('circle', { cx: 18, cy: 18, r: r, class: 'seg-ring-bg' }));
                node.appendChild(svgNode('circle', {
                    cx: 18, cy: 18, r: r, class: 'seg-ring-fg' + (share < 0.5 ? ' thin' : ''),
                    'stroke-dasharray': (share * c) + ' ' + c, transform: 'rotate(-90 18 18)'
                }));
                const text = svgNode('text', { x: 18, y: 21, class: 'seg-ring-text' });
                text.textContent = pct(share);
                node.appendChild(text);
                return node;
            };

            const $cards = $('#segCards').empty();
            for (const segment of mine) {
                const href = '/ui/lens/overview?hours=' + chosenHours()
                    + '&segment=' + encodeURIComponent(segment.interface);
                const $card = $('<a/>').addClass('content-box seg-card').attr('href', href);

                $card.append($('<div/>').addClass('seg-card-head')
                    .append($('<div/>')
                        .append($('<div/>').addClass('seg-card-name').text(segment.name))
                        .append($('<div/>').addClass('seg-if').text(segment.interface)))
                    .append(ring(segment.named_share)));

                $card.append($('<div/>').addClass('seg-card-num').text(segment.traffic));
                $card.append($('<div/>').addClass('seg-if').text(
                    segment.sent + ' \u2191 \u00b7 ' + segment.received + ' \u2193 \u00b7 '
                    + segment.addresses + ' {{ lang._("addresses") }}'));
                $card.append(sparkline(segment.series || []));
                if (segment.note) {
                    $card.append($('<div/>').addClass('seg-note').text(segment.note));
                }
                $cards.append($card);
            }

            const $other = $('#segOther').empty();
            for (const segment of other) {
                $other.append($('<tr/>')
                    .append($('<td/>').append($('<b/>').text(segment.name))
                        .append($('<span/>').addClass('seg-if').text(' ' + segment.interface)))
                    .append($('<td/>').addClass('seg-num').text(segment.traffic))
                    .append($('<td/>').addClass('seg-note').text(segment.note || '')));
            }
            $('#segOtherBox').toggle(other.length > 0);

            $('#segExport').on('click', (event) => {
                event.preventDefault();

                const rows = [[
                    '{{ lang._("Network") }}', '{{ lang._("Interface") }}',
                    '{{ lang._("Bytes") }}', '{{ lang._("Bytes with a device") }}',
                    '{{ lang._("Share named") }}', '{{ lang._("Addresses") }}',
                    '{{ lang._("Is one of your networks") }}'
                ]];

                for (const segment of report.segments) {
                    rows.push([
                        segment.name, segment.interface, segment.octets, segment.named,
                        pct(segment.named_share), segment.addresses,
                        segment.is_network ? 'yes' : 'no'
                    ]);
                }

                const stamp = new Date().toISOString().slice(0, 10);
                download(rows, 'lens-networks-' + stamp + '-' + chosenHours() + 'h.csv');
            }).show();

            $('#segReport').show();
        });
    });
</script>

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
    <div class="content-box lens-box">
        <p class="lens-box-head">
            <b id="segTotal"></b> {{ lang._('over') }} <span id="segHours"></span>,
            {{ lang._('across every interface NetFlow reported.') }}
        </p>

        <div id="segCards" class="seg-cards"></div>

        <p class="text-muted lens-box-foot">
            {{ lang._('A card opens the devices on that network. The ring is how much of its traffic Lens can put a device to: a network that is mostly unnamed is not a busy network, it is one whose traffic belongs to machines not attached to it.') }}
        </p>
    </div>

    <div id="segOtherBox" class="content-box lens-box" style="display: none;">
        <p class="lens-box-head">{{ lang._('Not your networks') }}</p>
        <table class="table table-condensed"><tbody id="segOther"></tbody></table>
    </div>
</div>
