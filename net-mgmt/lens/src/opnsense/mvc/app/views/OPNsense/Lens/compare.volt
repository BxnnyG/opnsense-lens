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
        /*
         * Up to four devices side by side (§4.73). Compare aligns them and says
         * the difference; this draws. A device's colour is its slot in the URL,
         * never its rank, so it does not move when the range changes.
         */
        const MAX = 4;
        const query = new URLSearchParams(location.search);
        const RANGES = { 24: '{{ lang._("24 hours") }}', 168: '{{ lang._("7 days") }}', 720: '{{ lang._("30 days") }}' };
        const hours = [24, 168, 720].includes(parseInt(query.get('hours'), 10)) ? parseInt(query.get('hours'), 10) : 168;
        const chosen = (query.get('devices') || '').split('|').filter(Boolean).slice(0, MAX);

        const go = (devices, h) => {
            const params = new URLSearchParams();
            if (devices.length) {
                params.set('devices', devices.join('|'));
            }
            params.set('hours', h);
            location.search = params.toString();
        };

        for (const h of [24, 168, 720]) {
            $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', h === hours).attr('href', '#')
                .text(RANGES[h]).on('click', (e) => { e.preventDefault(); go(chosen, h); })
                .appendTo('#cmpRange');
        }

        const svg = (tag, attrs) => {
            const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
            for (const [key, value] of Object.entries(attrs || {})) {
                node.setAttribute(key, value);
            }
            return node;
        };

        const pickers = (choices) => {
            const $box = $('#cmpPick').empty();
            const slots = chosen.length < MAX ? chosen.concat(['']) : chosen;
            slots.forEach((value, index) => {
                const $select = $('<select/>').addClass('form-control cmp-select')
                    .append($('<option/>').val('').text(index < chosen.length
                        ? '{{ lang._("(remove)") }}' : '{{ lang._("Add a device...") }}'));
                for (const choice of choices) {
                    $select.append($('<option/>').val(choice.macs).text(choice.name));
                }
                $select.val(value).on('change', function () {
                    const next = chosen.slice();
                    if (this.value) {
                        next[index] = this.value;
                    } else {
                        next.splice(index, 1);
                    }
                    go(next.filter(Boolean), hours);
                });
                $box.append($('<span/>').addClass('cmp-slot')
                    .append($('<span/>').addClass('cmp-key cmp-s' + (index + 1)))
                    .append($select));
            });
        };

        const chart = (report) => {
            const el = document.getElementById('cmpChart');
            while (el.firstChild) {
                el.removeChild(el.firstChild);
            }
            const width = 800;
            const height = 240;
            const pad = 12;
            const points = report.buckets;
            const peak = report.peak || 1;
            el.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
            if (points.length < 1) {
                return;
            }
            const x = (i) => points.length === 1 ? width / 2 : i / (points.length - 1) * width;
            const y = (v) => height - 1 - (v / peak) * (height - 1 - pad);

            el.appendChild(svg('line', { x1: 0, x2: width, y1: y(0), y2: y(0), class: 'lens-gridline' }));
            el.appendChild(svg('line', { x1: 0, x2: width, y1: y(peak), y2: y(peak), class: 'lens-gridline' }));
            for (const device of report.devices) {
                el.appendChild(svg('path', {
                    d: device.series.map((v, i) => (i ? 'L ' : 'M ') + x(i) + ' ' + y(v)).join(' '),
                    class: 'cmp-line cmp-s' + device.slot
                }));
            }
            Lens.axis(document.getElementById('cmpAxis'), points.map(at => ({ at: at })),
                      (i) => points.length === 1 ? 0.5 : i / (points.length - 1), report.step);

            const cross = svg('line', { x1: 0, x2: 0, y1: pad, y2: y(0), class: 'lens-crosshair', visibility: 'hidden' });
            el.appendChild(cross);
            const hit = svg('rect', { x: 0, y: 0, width: width, height: height, fill: 'transparent' });
            el.appendChild(hit);
            Lens.tip(hit, (event) => {
                const box = el.getBoundingClientRect();
                const i = Math.max(0, Math.min(points.length - 1,
                    Math.round((event.clientX - box.left) / box.width * (points.length - 1))));
                cross.setAttribute('x1', x(i));
                cross.setAttribute('x2', x(i));
                cross.setAttribute('visibility', 'visible');
                return [{ label: Lens.when(points[i], report.step, true) }].concat(report.devices.map(d => ({
                    value: bytes(d.series[i]), label: d.name, key: 'var(--lens-cmp-' + d.slot + ')'
                })));
            });
            hit.addEventListener('pointerleave', () => cross.setAttribute('visibility', 'hidden'));
            $('#cmpPeak').text(report.peak_text + ' {{ lang._("peak per") }} '
                + (report.step >= 86400 ? '{{ lang._("day") }}' : '{{ lang._("hour") }}'));
        };

        const bytes = (octets) => {
            if (!octets) {
                return '0 B';
            }
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let value = octets;
            let i = 0;
            while (value >= 1024 && i < units.length - 1) {
                value /= 1024;
                i++;
            }
            return (i && value < 10 ? value.toFixed(1) : Math.round(value)) + ' ' + units[i];
        };

        const table = (report) => {
            const $legend = $('#cmpLegend').empty();
            const $body = $('#cmpTable').empty();
            for (const d of report.devices) {
                $legend.append($('<span/>').addClass('cmp-legend')
                    .append($('<span/>').addClass('cmp-key cmp-s' + d.slot)).append(document.createTextNode(d.name)));
                $body.append($('<tr/>')
                    .append($('<td/>')
                        .append($('<span/>').addClass('cmp-key cmp-s' + d.slot))
                        .append($('<a/>').attr('href', '/ui/lens/device?mac=' + encodeURIComponent(d.mac))
                            .append($('<i/>').addClass('fa fa-fw ' + d.icon))
                            .append(document.createTextNode(' ' + d.name))))
                    .append($('<td/>').addClass('lens-num').attr('data-label', '{{ lang._("Moved") }}').text(d.total))
                    .append($('<td/>').addClass('lens-num').attr('data-label', '{{ lang._("Share") }}')
                        .text(d.share + ' %'))
                    .append($('<td/>').addClass('lens-num').attr('data-label', '{{ lang._("Sent") }}').text(d.sent))
                    .append($('<td/>').addClass('lens-num').attr('data-label', '{{ lang._("Received") }}')
                        .text(d.received))
                    .append($('<td/>').attr('data-label', '{{ lang._("Busiest") }}').text(d.busiest
                        ? d.busiest.what + ', ' + Lens.when(d.busiest.bucket, report.step, true) : '–')));
            }
        };

        ajaxGet('/api/lens/devices/compare', { devices: chosen.join('|'), hours: hours }, (report, status) => {
            $('#cmpLoading').hide();
            if (status !== 'success' || !report || !report.devices) {
                $('#cmpError').text((report && report.message) || '{{ lang._("Lens did not answer.") }}').show();
                return;
            }
            pickers(report.choices || []);
            $('#cmpSentence').text(report.sentence);
            $('#cmpBody').toggle(report.devices.length > 0);
            if (report.devices.length) {
                chart(report);
                table(report);
            }
        });
    });
</script>

<div class="lens-toolbar">
    <span id="cmpRange"></span>
</div>
<div id="cmpLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Lining them up...') }}</div>
<div id="cmpError" class="alert alert-danger" style="display: none;"></div>

<div class="content-box lens-box">
    <div id="cmpPick" class="cmp-pick"></div>
    <p id="cmpSentence" class="lens-lead"></p>

    <div id="cmpBody" style="display: none;">
        <div class="cmp-chart-head">
            <span id="cmpLegend"></span>
            <span id="cmpPeak" class="text-muted"></span>
        </div>
        <svg id="cmpChart" class="cmp-chart" preserveAspectRatio="none" role="img"
             aria-label="{{ lang._('Traffic of each device over the range') }}"></svg>
        <div id="cmpAxis" class="lens-axis-row"></div>

        <div class="table-responsive">
            <table class="table table-condensed lens-sources-table cmp-table">
                <thead><tr>
                    <th>{{ lang._('Device') }}</th>
                    <th class="lens-num">{{ lang._('Moved') }}</th>
                    <th class="lens-num">{{ lang._('Share') }}</th>
                    <th class="lens-num">{{ lang._('Sent') }}</th>
                    <th class="lens-num">{{ lang._('Received') }}</th>
                    <th>{{ lang._('Busiest') }}</th>
                </tr></thead>
                <tbody id="cmpTable"></tbody>
            </table>
        </div>
    </div>
</div>
