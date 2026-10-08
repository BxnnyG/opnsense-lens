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
         * What Lens keeps about the people on this network, what it reads and
         * does not keep, and forgetting one device (§4.72). Every sentence comes
         * from Privacy; this lays it out.
         */
        const wanted = new URLSearchParams(location.search).get('mac') || '';
        let devices = [];

        const kept = (report) => {
            $('#privHeadline').text(report.headline);
            const $body = $('#privKept').empty();
            for (const kind of report.kinds) {
                $body.append($('<tr/>')
                    .append($('<td/>')
                        .append($('<b/>').text(kind.what))
                        .append($('<div/>').addClass('text-muted').text(kind.holds)))
                    .append($('<td/>').addClass('text-muted').text(kind.why))
                    .append($('<td/>').addClass('lens-num').text(kind.rows.toLocaleString()))
                    .append($('<td/>').text(kind.oldest || '–'))
                    .append($('<td/>').text(kind.state || kind.kept_for)));
            }

            const $else = $('#privElsewhere').empty();
            for (const entry of report.elsewhere) {
                const $clear = entry.url
                    ? $('<a/>').attr('href', entry.url).text(entry.clear)
                    : $('<span/>').addClass('text-muted').text('–');
                $else.append($('<tr/>')
                    .append($('<td/>').append($('<b/>').text(entry.what)))
                    .append($('<td/>').text(entry.where))
                    .append($('<td/>').append($clear)));
            }
        };

        const pick = (list) => {
            devices = list;
            const $select = $('#privDevice').empty()
                .append($('<option/>').val('').text('{{ lang._("Choose a device...") }}'));
            for (const device of list) {
                const label = device.name + (device.owner ? ' (' + device.owner + ')' : '')
                    + (device.here ? '' : ' · {{ lang._("not here") }}');
                $select.append($('<option/>').val(device.macs.join(',')).text(label));
            }
            const match = list.find(d => d.macs.includes(wanted.split(',')[0]));
            if (match) {
                $select.val(match.macs.join(',')).trigger('change');
            }
        };

        const load = () => ajaxGet('/api/lens/privacy/kept', {}, (report, status) => {
            $('#privLoading').hide();
            if (status !== 'success' || !report || !report.kinds) {
                $('#privError').show();
                return;
            }
            kept(report);
            pick(report.devices || []);
            $('#privPage').show();
        });

        $('#privDevice').on('change', function () {
            const macs = $(this).val();
            $('#privDone').hide();
            $('#privForget').prop('disabled', true);
            $('#privPreview').text('');
            if (!macs) {
                return;
            }
            $('#privPreview').text('{{ lang._("Counting...") }}');
            ajaxGet('/api/lens/privacy/preview', { mac: macs }, (reply, status) => {
                if ($('#privDevice').val() !== macs) {
                    return;
                }
                const ok = status === 'success' && reply && reply.ok;
                /* a paused device is refused with its reason, not "no answer" (§4.74) */
                const said = status === 'success' && reply && (reply.ok || reply.paused);
                $('#privPreview').text(said ? reply.sentence : '{{ lang._("Lens did not answer.") }}');
                $('#privForget').prop('disabled', !(ok && reply.found));
            });
        });

        $('#privForget').on('click', () => {
            const device = devices.find(d => d.macs.join(',') === $('#privDevice').val());
            $('#privConfirmName').text(device ? device.name : '');
            $('#privConfirmWhat').text($('#privPreview').text());
            $('#privConfirmError').hide();
            $('#privConfirmApply').prop('disabled', false);
            $('#privConfirm').modal('show');
        });

        $('#privConfirmApply').on('click', function () {
            $(this).prop('disabled', true);
            ajaxCall('/api/lens/privacy/forget', { mac: $('#privDevice').val() }, (reply, status) => {
                if (status !== 'success' || !reply || !reply.ok) {
                    $('#privConfirmError')
                        .text((reply && reply.sentence) || '{{ lang._("Nothing was deleted.") }}').show();
                    $('#privConfirmApply').prop('disabled', false);
                    return;
                }
                $('#privConfirm').modal('hide');
                $('#privDone').text(reply.sentence).show();
                $('#privPreview').text('');
                $('#privForget').prop('disabled', true);
                load();
            });
        });

        load();
    });
</script>

<div id="privLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading what Lens keeps...') }}</div>
<div id="privError" class="alert alert-danger" style="display: none;">
    {{ lang._('Lens did not answer. The store may not exist yet: the collector creates it on its first run.') }}
</div>

<div id="privPage" style="display: none;">
    <p id="privHeadline" class="lens-lead"></p>

    <div class="content-box lens-box">
        <div class="lens-box-head">{{ lang._('What Lens keeps') }}</div>
        <div class="lens-box-intro">
            {{ lang._('Everything below is in /var/db/lens on this firewall and nowhere else. Nothing is sent anywhere.') }}
        </div>
        <div class="table-responsive">
            <table class="table table-condensed lens-sources-table">
                <thead><tr>
                    <th>{{ lang._('What') }}</th><th>{{ lang._('Why') }}</th>
                    <th class="lens-num">{{ lang._('Rows') }}</th><th>{{ lang._('Oldest') }}</th>
                    <th>{{ lang._('Kept for') }}</th>
                </tr></thead>
                <tbody id="privKept"></tbody>
            </table>
        </div>
        <p class="text-muted">
            {{ lang._('How long is set on') }} <a href="/ui/lens/settings">{{ lang._('Services: Lens: Settings') }}</a>,
            {{ lang._('which can also delete everything at once.') }}
        </p>
    </div>

    <div class="content-box lens-box">
        <div class="lens-box-head">{{ lang._('Forget one device') }}</div>
        <div class="lens-box-intro">
            {{ lang._("Deletes the device, your note about it, the addresses it held and every hour of traffic and every destination those addresses cover - also hours it shared an address with another device. The network totals drop by what it moved. Core's own copies below are not touched.") }}
        </div>
        <div class="lens-forget">
            <select id="privDevice" class="form-control"></select>
            <button type="button" class="btn btn-danger" id="privForget" disabled>{{ lang._('Forget this device') }}</button>
        </div>
        <p id="privPreview" class="lens-forget-note"></p>
        <p id="privDone" class="text-success" style="display: none;"></p>
    </div>

    <div class="content-box lens-box">
        <div class="lens-box-head">{{ lang._('What Lens reads and does not keep') }}</div>
        <div class="lens-box-intro">
            {{ lang._('OPNsense keeps these itself. Lens only reads them, and forgetting a device here does not delete them.') }}
        </div>
        <div class="table-responsive">
            <table class="table table-condensed lens-sources-table">
                <thead><tr>
                    <th>{{ lang._('What') }}</th><th>{{ lang._('Where, and for how long') }}</th>
                    <th>{{ lang._('Cleared on') }}</th>
                </tr></thead>
                <tbody id="privElsewhere"></tbody>
            </table>
        </div>
    </div>

    <div class="modal" id="privConfirm" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">{{ lang._('Forget') }} <span id="privConfirmName"></span></h4>
                </div>
                <div class="modal-body">
                    <p id="privConfirmWhat"></p>
                    <p>{{ lang._('This cannot be undone.') }}</p>
                    <p id="privConfirmError" class="text-danger" style="display: none;"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Keep it') }}</button>
                    <button type="button" class="btn btn-danger" id="privConfirmApply">{{ lang._('Forget this device') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
