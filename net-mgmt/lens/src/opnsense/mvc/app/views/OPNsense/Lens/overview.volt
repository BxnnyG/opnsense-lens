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
        ajaxGet('/api/lens/devices/list', {}, (report, deviceStatus) => {
            if (deviceStatus !== 'success' || !report || !report.devices) {
                $('#lensDevicesError').show();
                return;
            }

            $('#lensDevicesHeadline').text(report.headline || '');

            if (report.note) {
                $('#lensDevicesNote').text(report.note).show();
            }

            const $body = $('#lensDevices > tbody').empty();
            for (const device of report.devices) {
                const $name = $('<td/>')
                    .append($('<div/>').text(device.name))
                    .append($('<span/>').addClass('lens-mac').text(device.mac));
                if (device.role) {
                    $name.append($('<span/>').addClass('lens-role').text(device.role));
                }

                const $addresses = $('<td/>');
                for (const address of device.addresses) {
                    const $line = $('<span/>').addClass('lens-addr').text(address.address);
                    $line.append($('<span/>').addClass('lens-if').text(' · ' + address.interface));
                    if (!address.current) {
                        $line.addClass('lens-addr-gone')
                             .append($('<span/>').text(' (' + address.seen + ')'));
                    }
                    $addresses.append($line);
                }
                if (!device.addresses.length) {
                    $addresses.text('{{ lang._("no address recorded") }}');
                }

                const $presence = $('<td/>').append(
                    $('<span/>').addClass(device.here ? 'lens-here' : '').text(device.presence)
                );

                const $named = $('<td/>').addClass('lens-cause').text(device.named_by);
                if (device.caveat) {
                    $named.append($('<em/>').addClass('lens-caveat').text(device.caveat));
                }

                $body.append($('<tr/>')
                    .append($name)
                    .append($addresses)
                    .append($presence)
                    .append(cell(device.known_for))
                    .append($named));
            }

            $('#lensDevicesBlock').show();
        });

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
    <div id="lensDevicesNote" class="alert alert-warning" style="display: none;"></div>
    <table id="lensDevices" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Device') }}</th>
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
    </p>
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
