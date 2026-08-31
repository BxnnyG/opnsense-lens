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
    .lens-traffic { white-space: nowrap; min-width: 14em; }
    .lens-icon { margin-right: 6px; opacity: 0.75; }
    .lens-name { font-weight: 600; }
    .lens-mac { display: block; margin-left: 20px; }
    .lens-role { margin-left: 20px; }
    .lens-bar {
        display: block; height: 4px; margin-bottom: 3px;
        background: #d94f00; opacity: 0.55; min-width: 2px; border-radius: 2px;
    }
    .lens-bytes { font-size: 95%; }
    .lens-addr-toggle { display: block; font-size: 90%; }
    #lensControls { margin: 10px 0 14px 0; }
    #lensSearch { width: 22em; max-width: 100%; }
    #lensSegments { display: inline-block; margin-left: 6px; }
    .lens-chip {
        display: inline-block; padding: 1px 8px; margin: 2px 3px;
        border: 1px solid #999; border-radius: 10px; font-size: 90%;
        text-decoration: none;
    }
    .lens-chip-on { border-color: #d94f00; color: #d94f00; font-weight: 600; }
    #lensShowing { margin-left: 8px; color: #999; }
    .lens-edit { margin-left: 6px; opacity: 0.35; }
    tr:hover .lens-edit { opacity: 1; }
    .lens-tag {
        display: inline-block; margin: 3px 4px 0 20px;
        padding: 0 6px; border: 1px solid #999; border-radius: 8px; font-size: 85%;
    }
    .lens-note { display: block; margin-left: 20px; font-size: 90%; color: #999; }
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
        /*
         * Devices first: this is the answer the page exists to give. Loaded on
         * its own request so a slow source probe cannot delay it, and so one
         * failing does not blank the other.
         *
         * The report arrives once and is filtered in the browser. Fifty devices
         * is not a dataset -- it is a list a person is trying to find one thing
         * in, and a round trip per keystroke would make that worse, not better.
         */
        let devices = [];
        let segments = new Set();
        let kinds = {};
        let editing = null;

        const render = () => {
            const needle = ($('#lensSearch').val() || '').toLowerCase().trim();
            const onlyBusy = $('#lensOnlyTraffic').is(':checked');

            const shown = devices.filter(device => {
                if (needle && device.haystack.indexOf(needle) === -1) {
                    return false;
                }
                if (onlyBusy && !device.octets) {
                    return false;
                }
                if (segments.size && !device.interfaces.some(i => segments.has(i))) {
                    return false;
                }
                return true;
            });

            /* the bar is relative to what is on screen, so filtering to one
               segment rescales it instead of leaving every bar a sliver */
            const largest = shown.reduce((max, d) => Math.max(max, d.octets), 0);

            const $body = $('#lensDevices > tbody').empty();
            for (const device of shown) {
                $body.append(deviceRow(device, largest));
            }

            $('#lensShowing').text(
                shown.length === devices.length
                    ? ''
                    : shown.length + ' {{ lang._("of") }} ' + devices.length
            );
            $('#lensEmpty').toggle(shown.length === 0 && devices.length > 0);
        };

        const deviceRow = (device, largest) => {
            const $name = $('<td/>');
            $name.append($('<i/>')
                .addClass('fa fa-fw lens-icon ' + device.kind.icon)
                .attr('title', device.kind.type));
            $name.append($('<span/>').addClass('lens-name').text(device.name));
            $name.append($('<a/>').addClass('lens-edit').attr('href', '#')
                .attr('title', '{{ lang._("Give this device a name of your own") }}')
                .append($('<i/>').addClass('fa fa-pencil'))
                .on('click', function (event) {
                    event.preventDefault();
                    openEditor(device);
                }));
            $name.append($('<span/>').addClass('lens-mac').text(device.mac));
            if (device.role) {
                $name.append($('<span/>').addClass('lens-role').text(device.role));
            }
            for (const tag of device.tags) {
                $name.append($('<span/>').addClass('lens-tag').text(tag));
            }
            if (device.label.note) {
                $name.append($('<span/>').addClass('lens-note').text(device.label.note));
            }

            const $traffic = $('<td/>').addClass('lens-traffic');
            if (device.octets) {
                $traffic.append($('<span/>').addClass('lens-bar').css(
                    'width', largest ? Math.max(2, (device.octets / largest) * 100) + '%' : 0
                ));
                $traffic.append($('<span/>').addClass('lens-bytes').text(device.traffic));
            }

            const $addresses = $('<td/>');
            if (!device.addresses.length) {
                $addresses.text('{{ lang._("no address recorded") }}');
            }
            device.addresses.forEach((address, index) => {
                const $line = $('<span/>').addClass('lens-addr').text(address.address);
                $line.append($('<span/>').addClass('lens-if').text(' \u00b7 ' + address.interface));
                if (!address.current) {
                    $line.addClass('lens-addr-gone')
                         .append($('<span/>').text(' (' + address.seen + ')'));
                }
                /* the firewall holds twelve addresses and drowned every other
                   row on the page; the rest are one click away */
                if (index >= 3) {
                    $line.addClass('lens-addr-more').hide();
                }
                $addresses.append($line);
            });
            if (device.addresses.length > 3) {
                $addresses.append($('<a/>')
                    .addClass('lens-addr-toggle').attr('href', '#')
                    .text('+ ' + (device.addresses.length - 3) + ' {{ lang._("more") }}')
                    .on('click', function (event) {
                        event.preventDefault();
                        $(this).siblings('.lens-addr-more').toggle();
                        $(this).text($(this).siblings('.lens-addr-more:visible').length
                            ? '{{ lang._("show fewer") }}'
                            : '+ ' + (device.addresses.length - 3) + ' {{ lang._("more") }}');
                    }));
            }

            const $presence = $('<td/>').append(
                $('<span/>').addClass(device.here ? 'lens-here' : '').text(device.presence)
            );

            const $named = $('<td/>').addClass('lens-cause').text(device.named_by);
            if (device.caveat) {
                $named.append($('<em/>').addClass('lens-caveat').text(device.caveat));
            }

            return $('<tr/>')
                .append($name)
                .append($traffic)
                .append($addresses)
                .append($presence)
                .append(cell(device.known_for))
                .append($named);
        };

        const openEditor = (device) => {
            editing = device;

            $('#lensEditFor').text(device.mac);
            $('#lensEditName').val(device.label.name);
            $('#lensEditTags').val(device.label.tags);
            $('#lensEditNote').val(device.label.note);

            const $kind = $('#lensEditKind').empty();
            $('<option/>').val('').text(
                '{{ lang._("work it out from the hardware vendor") }}'
                + ' \u2014 ' + device.kind.type
            ).appendTo($kind);
            for (const key of Object.keys(kinds)) {
                $('<option/>').val(key).text(kinds[key]).appendTo($kind);
            }
            $kind.val(device.label.kind || '');

            $('#lensEditError').hide();
            $('#lensEditor').modal('show');
        };

        const saveLabel = () => {
            if (!editing) {
                return;
            }

            const payload = {
                mac: editing.mac,
                name: $('#lensEditName').val(),
                kind: $('#lensEditKind').val(),
                tags: $('#lensEditTags').val(),
                note: $('#lensEditNote').val()
            };

            $('#lensEditSave').prop('disabled', true);
            ajaxCall('/api/lens/devices/label', payload, (reply, saveStatus) => {
                $('#lensEditSave').prop('disabled', false);

                if (saveStatus !== 'success' || !reply || reply.status !== 'ok') {
                    $('#lensEditError')
                        .text((reply && reply.message) || '{{ lang._("The name was not saved.") }}')
                        .show();
                    return;
                }

                /* re-read rather than patch the row in place: the name changes
                   what the search matches and what the label column says, and
                   one source of truth is cheaper than keeping two agreeing */
                $('#lensEditor').modal('hide');
                load();
            });
        };

        const drawSegments = () => {
            const counts = new Map();
            for (const device of devices) {
                for (const name of device.interfaces) {
                    counts.set(name, (counts.get(name) || 0) + 1);
                }
            }

            const $bar = $('#lensSegments').empty();
            for (const [name, count] of [...counts.entries()].sort()) {
                $('<a/>').addClass('lens-chip').attr('href', '#')
                    .toggleClass('lens-chip-on', segments.has(name))
                    .text(name + ' (' + count + ')')
                    .on('click', function (event) {
                        event.preventDefault();
                        if (segments.has(name)) {
                            segments.delete(name);
                        } else {
                            segments.add(name);
                        }
                        $(this).toggleClass('lens-chip-on', segments.has(name));
                        render();
                    })
                    .appendTo($bar);
            }
        };

        const load = () => ajaxGet('/api/lens/devices/list', {}, (report, deviceStatus) => {
            if (deviceStatus !== 'success' || !report || !report.devices) {
                $('#lensDevicesError').show();
                return;
            }

            devices = report.devices;
            kinds = report.kinds || {};
            $('#lensDevicesHeadline').text(report.headline || '');

            if (report.note) {
                $('#lensDevicesNote').text(report.note).show();
            }

            drawSegments();
            render();

            $('#lensControls').toggle(devices.length > 0);

            const accounting = report.accounting || {};
            $('#lensAttributed').text(accounting.attributed || '0 B');
            $('#lensAttributedHours').text(accounting.hours || 0);

            const $acc = $('#lensAccounting > tbody').empty();
            for (const row of (accounting.rows || [])) {
                $acc.append($('<tr/>')
                    .append($('<td/>').addClass('lens-traffic').text(row.what))
                    .append($('<td/>').addClass('lens-cause').text(row.why)));
            }
            if ((accounting.rows || []).length) {
                $('#lensAccountingBlock').show();
            }

            $('#lensDevicesBlock').show();
        });

        load();
        $('#lensSearch').on('input', render);
        $('#lensOnlyTraffic').on('change', render);
        $('#lensEditSave').on('click', saveLabel);

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

    <div id="lensControls" style="display: none;">
        <input type="text" id="lensSearch" class="form-control input-sm"
               style="display: inline-block;"
               placeholder="{{ lang._('Search a name, address, MAC or vendor') }}">
        <label style="font-weight: normal; margin: 0 0 0 10px;">
            <input type="checkbox" id="lensOnlyTraffic"> {{ lang._('only devices with traffic') }}
        </label>
        <span id="lensShowing"></span>
        <div id="lensSegments"></div>
    </div>

    <div id="lensEmpty" class="alert alert-info" style="display: none;">
        {{ lang._('No device matches. Clear the search, or switch the segment filters off.') }}
    </div>

    <table id="lensDevices" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Device') }}</th>
                <th>{{ lang._('Traffic') }}</th>
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
        {{ lang._('Traffic is joined onto whoever held the address at the hour it was measured, not onto whoever holds it now.') }}
    </p>

    <div class="modal" id="lensEditor" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">{{ lang._('What do you call this device?') }}</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        {{ lang._('Kept apart from what the box observed, against') }}
                        <span id="lensEditFor" class="lens-mac"></span>.
                        {{ lang._('It survives a new address, a new lease and a rename, and no observation ever writes over it.') }}
                    </p>
                    <div id="lensEditError" class="alert alert-danger" style="display: none;"></div>
                    <div class="form-group">
                        <label for="lensEditName">{{ lang._('Name') }}</label>
                        <input type="text" class="form-control" id="lensEditName"
                               placeholder="{{ lang._('leave empty to keep the observed name') }}">
                    </div>
                    <div class="form-group">
                        <label for="lensEditKind">{{ lang._('Kind') }}</label>
                        <select class="form-control" id="lensEditKind"></select>
                    </div>
                    <div class="form-group">
                        <label for="lensEditTags">{{ lang._('Tags') }}</label>
                        <input type="text" class="form-control" id="lensEditTags"
                               placeholder="{{ lang._('comma separated, e.g. hypervisor, production') }}">
                    </div>
                    <div class="form-group">
                        <label for="lensEditNote">{{ lang._('Note') }}</label>
                        <input type="text" class="form-control" id="lensEditNote">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="lensEditSave">{{ lang._('Save') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div id="lensAccountingBlock" class="lens-block" style="display: none;">
        <h3>{{ lang._('What the traffic above does not cover') }}</h3>
        <p>
            <span id="lensAttributed"></span>
            {{ lang._('was attributed to a device over the last') }}
            <span id="lensAttributedHours"></span> {{ lang._('hours. The rest is here, rather than quietly missing.') }}
        </p>
        <table id="lensAccounting" class="table table-condensed">
            <tbody></tbody>
        </table>
    </div>
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
