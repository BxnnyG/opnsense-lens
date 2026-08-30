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
    {{ lang._('Lens is installed and has nothing to show yet. Until it can name devices instead of addresses, this page reports what it can see.') }}
    <a href="/ui/lens/preflight">{{ lang._('Data sources are configured under Services: Lens.') }}</a>
</p>

<script>
    $(document).ready(() => {
        /*
         * Layout only. What a source "is" is decided in PHP (SourceProbe), so
         * that the second surface to show this cannot decide it differently.
         */
        const text = (value) => $('<td/>').text(value === undefined ? '' : value);

        const mark = (answered) => $('<td/>').append(
            $('<span/>')
                .addClass(answered ? 'text-success' : 'text-muted')
                .text(answered ? '{{ lang._("answering") }}' : '{{ lang._("silent") }}')
        );

        ajaxGet('/api/lens/sources/probe', {}, (report, requestStatus) => {
            $('#lensLoading').hide();

            if (requestStatus !== 'success' || !report || !report.sources) {
                $('#lensError').show();
                return;
            }

            $('#lensVersion').text(report.version || '{{ lang._("unknown") }}');

            const answering = report.sources.filter(s => s.answered).length;
            $('#lensHeadline').text(
                answering + ' {{ lang._("of") }} ' + report.sources.length
                + ' {{ lang._("data sources are answering on this box.") }}'
            );

            const $body = $('#lensSources > tbody').empty();
            for (const source of report.sources) {
                $body.append($('<tr/>')
                    .append(text(source.label))
                    .append(mark(source.answered))
                    .append(text(source.detail))
                    .append(text(source.enables)));
            }

            $('#lensReport').show();
        });
    });
</script>

<div id="lensLoading">
    <i class="fa fa-spinner fa-spin"></i>
    {{ lang._('Asking the box which data sources answer...') }}
</div>

<div id="lensError" class="alert alert-danger" style="display: none;">
    {{ lang._('The source probe did not answer. Lens is installed, but something between this page and configd is not working.') }}
</div>

<div id="lensReport" style="display: none;">
    <p id="lensHeadline"></p>

    <table id="lensSources" class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ lang._('Source') }}</th>
                <th>{{ lang._('State') }}</th>
                <th>{{ lang._('Answer') }}</th>
                <th>{{ lang._('What it makes possible') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <p class="text-muted">
        {{ lang._('Lens version') }} <span id="lensVersion"></span>.
        {{ lang._('A source that answers is reachable. Whether it holds useful data, and how far back, is a different question -- that is the next stage.') }}
    </p>
</div>
