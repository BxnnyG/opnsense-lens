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
    {{ lang._('What this box can actually tell Lens, and where it cannot. Switching the missing sources on, with the cost of each stated, is what this page becomes next.') }}
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
</style>

<script>
    $(document).ready(() => {
        let plan = null;

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

            /*
             * The only thing on any Lens page that writes to the firewall's own
             * configuration, and it lives here rather than on the Reporting page
             * on purpose (§4.12): the views stay read-only, the Services page is
             * where you connect things.
             */
            if (report.fix) {
                plan = report.fix;
                $('#lensFixTitle').text(plan.title);
                $('#lensFix').show();
            } else {
                $('#lensFix').hide();
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
        $('#lensFixOpen').on('click', (event) => {
            event.preventDefault();
            if (!plan) {
                return;
            }

            $('#lensFixWhat').text(plan.title);
            $('#lensFixSetting').text(plan.setting);
            $('#lensFixBefore').text(plan.before);
            $('#lensFixAfter').text(plan.after);

            const $costs = $('#lensFixCosts').empty();
            for (const cost of plan.costs) {
                $costs.append($('<li/>').text(cost));
            }

            $('#lensFixError').hide();
            $('#lensFixDone').hide();
            $('#lensFixApply').show().prop('disabled', false);
            $('#lensFixDialog').modal('show');
        });

        $('#lensFixApply').on('click', function () {
            $(this).prop('disabled', true);

            /* no interfaces are sent: the server works out again what it
               offered, so this cannot become a general NetFlow write */
            ajaxCall('/api/lens/sources/applyFix', {}, (reply, fixStatus) => {
                if (fixStatus !== 'success' || !reply || reply.status !== 'ok') {
                    $('#lensFixError')
                        .text((reply && reply.message) || '{{ lang._("Nothing was changed.") }}')
                        .show();
                    $('#lensFixApply').prop('disabled', false);
                    return;
                }

                $('#lensFixApply').hide();
                $('#lensFixDone').text(
                    reply.result + ' \u2014 '
                    + '{{ lang._("NetFlow has been restarted. Reload this page to see it.") }}'
                ).show();
            });
        });

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

    <div id="lensFix" class="alert alert-warning lens-block" style="display: none;">
        <b id="lensFixTitle"></b>
        &mdash; <a href="#" id="lensFixOpen">{{ lang._('see exactly what would change') }}</a>
    </div>

    <div class="modal" id="lensFixDialog" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title" id="lensFixWhat"></h4>
                </div>
                <div class="modal-body">
                    <div id="lensFixError" class="alert alert-danger" style="display: none;"></div>
                    <div id="lensFixDone" class="alert alert-success" style="display: none;"></div>

                    <p>{{ lang._('One setting changes:') }} <b id="lensFixSetting"></b></p>
                    <table class="table table-condensed">
                        <tbody>
                            <tr>
                                <td style="width: 6em;">{{ lang._('now') }}</td>
                                <td id="lensFixBefore"></td>
                            </tr>
                            <tr>
                                <td>{{ lang._('after') }}</td>
                                <td id="lensFixAfter"></td>
                            </tr>
                        </tbody>
                    </table>

                    <p>{{ lang._('What that costs:') }}</p>
                    <ul id="lensFixCosts"></ul>

                    <p class="text-muted">
                        {{ lang._('Nothing else is touched, and the same page that owns this setting can undo it.') }}
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="lensFixApply">
                        {{ lang._('Change it') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

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
