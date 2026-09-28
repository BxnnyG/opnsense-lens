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
        /* one row of a ranked list: a name, what it is, a bar, a figure */
        const ranked = (name, sub, bar, figure, options = {}) => {
            const $name = options.link
                ? $('<a/>').attr('href', options.link).text(name)
                : $('<span/>').text(name);
            const $label = $('<div/>').addClass('dns-name').append($name);
            if (sub) {
                $label.append($('<div/>').addClass('dv-sub').text(sub));
            }
            return $('<div/>').addClass('dns-row').toggleClass('dns-blocked', !!options.blocked)
                .append($label)
                .append($('<div/>').addClass('lens-track').append(
                    $('<div/>').addClass('lens-fill').css('width', Math.max(2, bar) + '%')))
                .append($('<div/>').addClass('dv-num-cell').text(figure));
        };

        const RANGES = { 24: '{{ lang._("24 hours") }}', 168: '{{ lang._("7 days") }}' };
        const hours = parseInt(new URLSearchParams(location.search).get('hours'), 10) === 168 ? 168 : 24;
        for (const h of [24, 168]) {
            $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', h === hours)
                .attr('href', location.pathname + '?hours=' + h).text(RANGES[h]).appendTo('#dnsRange');
        }

        /* who asked what (§4.70): a device, and under it the names it asked most */
        let byDevice = [];
        const drawByDevice = () => {
            const $list = $('#dnsByDevice').empty();
            for (const device of byDevice.filter(d => Lens.filter.matches(d))) {
                const $names = $('<div/>').addClass('dns-names').hide();
                for (const domain of device.domains) {
                    const $line = $('<div/>').addClass('dns-name-line')
                        .append($('<span/>').text(domain.domain))
                        .append($('<span/>').addClass('dv-num-cell').text(domain.count));
                    if (domain.blocked) {
                        $line.find('span').first().append($('<span/>').addClass('dns-flag')
                            .text('{{ lang._("blocked") }}' + (domain.blocklist ? ' \u00b7 ' + domain.blocklist : '')));
                    }
                    $names.append($line);
                }
                const $row = ranked(device.name, device.names + ' {{ lang._("names") }} \u00b7 '
                    + device.blocked + ' {{ lang._("blocked") }} (' + device.blocked_pct + ')',
                    device.bar, device.queries, { link: device.link });
                const $open = $('<a/>').attr('href', '#').addClass('dns-open').text('{{ lang._("names") }} \u25be')
                    .on('click', (event) => {
                        event.preventDefault();
                        $names.slideToggle(100);
                    });
                $row.find('.dns-name').append($open);
                $list.append($row).append($names);
            }
            $('#dnsNoByDevice').toggle(!byDevice.length);
        };

        ajaxGet('/api/lens/dns/overview', { hours: hours }, (report, status) => {
            $('#dnsLoading').hide();
            if (status !== 'success' || !report || !report.state) {
                $('#dnsError').show();
                return;
            }
            if (report.state.key !== 'ok') {
                $('#dnsStateText').text(report.state.text);
                $('#dnsStateLink').toggle(!!report.state.link).attr('href', report.state.link || '#');
                $('#dnsState').show();
                return;
            }

            const figures = report.figures;
            $('#dnsHeadline').text(report.headline);
            $('#dnsTotal').text(figures.total);
            $('#dnsSince').text(figures.since
                ? '{{ lang._("since") }} ' + new Date(figures.since * 1000).toLocaleDateString([], {
                    weekday: 'short', day: 'numeric', month: 'short' })
                : '');
            $('#dnsBlocked').text(figures.blocked_pct);
            $('#dnsBlockedCount').text(figures.blocked + ' {{ lang._("blocked") }}');
            $('#dnsUpstream').text(figures.resolved_pct);
            $('#dnsBlocklist').text(figures.blocklist);

            const $top = $('#dnsTop').empty();
            for (const row of report.top) {
                $top.append(ranked(row.domain, null, row.bar, row.count));
            }
            const $blocked = $('#dnsTopBlocked').empty();
            for (const row of report.blocked) {
                $blocked.append(ranked(row.domain, row.blocklist, row.bar, row.count, { blocked: true }));
            }
            $('#dnsNoBlocked').toggle(!report.blocked.length);

            clients = report.clients;
            drawClients();

            /* from Unbound's own store: every question, by device and by name */
            const store = report.source === 'store';
            $('#dnsClientsBox').toggle(!store);
            $('#dnsStoreBoxes').toggle(store);
            $('#dnsRange').toggle(store);
            if (store) {
                byDevice = report.by_device || [];
                drawByDevice();
                const $names = $('#dnsByName').empty();
                for (const name of report.by_name || []) {
                    $names.append(ranked(name.domain,
                        (name.blocked ? '{{ lang._("blocked") }} ' + name.blocked_count
                            + (name.blocklist ? ' \u00b7 ' + name.blocklist : '') + ' \u00b7 ' : '')
                        + name.askers.map(a => a.name + ' ' + a.count).join(', '),
                        name.bar, name.count));
                }
            }
            $('#dnsReport').show();
        });

        /* only the devices follow the filter: the names above are the whole
           resolver's, and say so in their titles (§4.65) */
        let clients = null;
        const drawClients = () => {
            const $clients = $('#dnsClients').empty();
            const shown = clients.filter(row => Lens.filter.matches(row.placed ? row : { interfaces: [], tags: [] }));
            for (const row of shown) {
                $clients.append(ranked(row.name, row.sub, row.bar, row.queries, { link: row.link }));
            }
            $('#dnsNoClients').toggle(!shown.length);
        };
        Lens.filter.mount(document.getElementById('dnsFilterHere'), () => {
            if (clients) {
                drawClients();
                drawByDevice();
            }
        });
    });
</script>

<div class="who-head">
    <div>{{ lang._('What the devices here asked Unbound for, and what its blocklists stopped - by device, not by address. Lens keeps none of it; it reads Unbound\'s own seven days when this page opens.') }}
        <a href="/ui/unbound/overview">{{ lang._('Reporting: Unbound DNS') }} &rsaquo;</a></div>
    <div id="dnsRange" style="display: none;"></div>
</div>

<div id="dnsLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Asking Unbound...') }}</div>
<div id="dnsError" class="alert alert-danger" style="display: none;">
    {{ lang._('The DNS report did not come back.') }}
</div>

<div id="dnsState" class="content-box lens-box" style="display: none;">
    <div class="ev-headline" id="dnsStateText"></div>
    <a href="#" id="dnsStateLink">{{ lang._('Open it') }} &rsaquo;</a>
</div>

<div id="dnsReport" style="display: none;">
    <div class="ev-headline" id="dnsHeadline" style="margin-bottom: 12px;"></div>
    <div class="dv-facts">
        <div class="content-box dv-fact"><div class="dv-num" id="dnsTotal"></div>
            <div class="dv-sub">{{ lang._('questions') }} <span id="dnsSince"></span></div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dnsBlocked"></div>
            <div class="dv-sub" id="dnsBlockedCount"></div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dnsUpstream"></div>
            <div class="dv-sub">{{ lang._('had to be asked upstream') }}</div></div>
        <div class="content-box dv-fact"><div class="dv-num" id="dnsBlocklist"></div>
            <div class="dv-sub">{{ lang._('names on the blocklists') }}</div></div>
    </div>

    <div class="dv-grid">
        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Asked most, the whole network') }}</span></div>
            <div id="dnsTop"></div>
        </div>
        <div class="content-box dv-card">
            <div class="dv-title"><span>{{ lang._('Blocked most, the whole network') }}</span></div>
            <div id="dnsTopBlocked"></div>
            <div id="dnsNoBlocked" class="dv-sub">{{ lang._('Nothing was blocked - or no blocklist is switched on.') }}</div>
        </div>
    </div>

    <div id="dnsFilterHere"></div>
    <div id="dnsStoreBoxes" style="display: none;">
        <div class="dv-grid" style="margin-top: 14px;">
            <div class="content-box dv-card">
                <div class="dv-title"><span>{{ lang._('Who asked what') }}</span></div>
                <div id="dnsByDevice"></div>
                <div id="dnsNoByDevice" class="dv-sub" style="display: none;">{{ lang._('No device asked anything in this range.') }}</div>
            </div>
            <div class="content-box dv-card">
                <div class="dv-title"><span>{{ lang._('What was asked, and by whom') }}</span></div>
                <div id="dnsByName"></div>
            </div>
        </div>
        <div class="lens-note-under">
            {{ lang._('Every question in the range, from Unbound\'s own record. Each hour\'s questions from an address belong to the device that alone held it in that hour; an hour two devices shared belongs to nobody.') }}
        </div>
    </div>

    <div id="dnsClientsBox" class="content-box dv-card" style="margin-top: 14px;">
        <div class="dv-title"><span>{{ lang._('Devices asking most, last 24 hours') }}</span></div>
        <div id="dnsClients"></div>
        <div id="dnsNoClients" class="dv-sub">{{ lang._('Unbound named no clients for the last day.') }}</div>
        <div class="lens-note-under">
            {{ lang._('Each ten minutes, Unbound keeps its ten busiest clients. Every one is put on the device that held its address in those ten minutes - not the one that holds it now.') }}
        </div>
    </div>
</div>
