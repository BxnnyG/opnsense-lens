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
        const RANGES = { 24: '{{ lang._("24 hours") }}', 168: '{{ lang._("7 days") }}', 720: '{{ lang._("30 days") }}' };
        const query = new URLSearchParams(location.search);
        const hours = [24, 168, 720].includes(parseInt(query.get('hours'), 10)) ? parseInt(query.get('hours'), 10) : 24;
        for (const h of [24, 168, 720]) {
            $('<a/>').addClass('lens-chip').toggleClass('lens-chip-on', h === hours)
                .attr('href', location.pathname + '?hours=' + h + location.hash).text(RANGES[h]).appendTo('#sysRange');
        }

        const cell = (text, cls) => $('<td/>').addClass(cls || '').text(text === null || text === undefined ? '—' : text);
        const dot = tone => $('<span/>').addClass('seg-dot health-' + tone);
        const table = (head, rows) => {
            const $t = $('<table/>').addClass('table table-condensed lens-sys-table');
            $t.append($('<thead/>').append($('<tr/>').append(head.map(h => $('<th/>').text(h)))));
            $t.append($('<tbody/>').append(rows));
            return $t;
        };

        /* one section per tile: its sentence, its list, and core's page inside it */
        const section = (tile) => {
            const $s = $('<div/>').addClass('content-box dv-card lens-sys-section').attr('id', tile.key);
            const $head = $('<div/>').addClass('dv-title')
                .append($('<span/>').append(dot(tile.tone)).append($('<i/>').addClass('fa fa-fw ' + tile.icon))
                    .append(document.createTextNode(' ' + tile.title)));
            if (tile.core) {
                $head.append($('<a/>').addClass('lens-sys-core').attr('href', tile.core)
                    .text('{{ lang._("Open in OPNsense") }} ›'));
            }
            return $s.append($head).append($('<div/>').addClass('lens-sys-sentence').text(tile.sentence))
                .append($('<div/>').addClass('lens-sys-body'));
        };

        const fill = {
            system: ($body) => {
                $body.append($('<div/>').attr('id', 'sysProcessor')).append($('<div/>').attr('id', 'sysMemory'));
            },
            temperature: ($body, details) => {
                $body.append($('<div/>').attr('id', 'sysTemperature'));
                $body.append(table(['{{ lang._("Sensor") }}', '{{ lang._("now") }}'],
                    (details.temperature || []).map(t => $('<tr/>').append(cell(t.sensor))
                        .append(cell(t.celsius + ' °C', 'lens-num')))));
            },
            updates: ($body, details) => {
                const u = details.updates || {};
                $body.append($('<div/>').addClass('dash-sub').text('{{ lang._("running") }} ' + (u.version || '?')
                    + (u.reboot ? ' · {{ lang._("the update needs a reboot") }}' : '')));
                if ((u.packages || []).length) {
                    $body.append(table(['{{ lang._("Package") }}', '{{ lang._("now") }}', '{{ lang._("then") }}'],
                        u.packages.map(p => $('<tr/>').append(cell(p.name)).append(cell(p.old)).append(cell(p.new)))));
                }
            },
            services: ($body, details) => {
                $body.append(table(['', '{{ lang._("Service") }}', '{{ lang._("State") }}'],
                    (details.services || []).map(s => $('<tr/>')
                        .append($('<td/>').append(dot(!s.checked ? 'grey' : (s.running ? 'good' : 'bad'))))
                        .append(cell(s.description))
                        .append(cell(!s.checked ? '{{ lang._("not checked by OPNsense") }}'
                            : (s.running ? '{{ lang._("running") }}' : '{{ lang._("stopped") }}'))))));
            },
            certificates: ($body, details) => {
                $body.append(table(['', '{{ lang._("Certificate") }}', '{{ lang._("Expires") }}', '{{ lang._("Used by") }}'],
                    (details.certificates || []).map(c => $('<tr/>')
                        .append($('<td/>').append(dot(c.tone)))
                        .append(cell(c.name))
                        .append(cell(new Date(c.expires * 1000).toLocaleDateString() + ' (' + c.days + ' {{ lang._("days") }})'))
                        .append(cell(c.in_use ? c.users.join(', ') : '{{ lang._("nothing") }}')))));
            },
            smart: ($body, details) => {
                $body.append(table(['', '{{ lang._("Disk") }}', '{{ lang._("Verdict") }}'],
                    (details.smart || []).map(d => $('<tr/>')
                        .append($('<td/>').append(dot(d.passed === null ? 'grey' : (d.passed ? 'good' : 'bad'))))
                        .append(cell(d.device + ' ' + d.ident))
                        .append(cell(d.passed === null ? '{{ lang._("no SMART") }}'
                            : (d.passed ? '{{ lang._("healthy") }}' : '{{ lang._("failing") }}'))))));
            },
            dyndns: ($body, details) => {
                $body.append(table(['{{ lang._("Name") }}', '{{ lang._("points at") }}'],
                    (details.dyndns || []).map(n => $('<tr/>').append(cell(n.name)).append(cell(n.ip)))));
            },
            wireguard: ($body, details) => {
                $body.append(table(['', '{{ lang._("Peer") }}', '{{ lang._("Last seen") }}', '▼', '▲'],
                    (details.wireguard || []).map(p => $('<tr/>')
                        .append($('<td/>').append(dot(p.online ? 'good' : 'grey')))
                        .append(cell(p.name + ' (' + p.interface + ')'))
                        .append(cell(p.online ? '{{ lang._("connected") }}' : p.seen))
                        .append(cell(p.received, 'lens-num')).append(cell(p.sent, 'lens-num')))));
            },
            interfaces: ($body) => {
                $body.append($('<a/>').attr('href', '/ui/lens/segments')
                    .text('{{ lang._("Every network with its port and the devices on it") }} ›'));
            },
        };

        /* the firewall's own history, as System: Health records it (stage 53) */
        const draw = (history) => {
            const unitOf = (rrd) => {
                const units = Object.values(rrd.units || {});
                return units.length ? units[0] : '';
            };
            const one = (key, el) => {
                const rrd = history[key];
                if (!rrd || !el) {
                    return;
                }
                /* one axis: only the series in the chart's first unit */
                const unit = unitOf(rrd);
                const same = (rrd.series || []).filter(s => !rrd.units || !Object.keys(rrd.units).length
                    || (rrd.units[s.key] || unit) === unit);
                $(el).append($('<div/>').addClass('dv-sub lens-sys-chart-title').text(rrd.title));
                const box = document.createElement('div');
                el.appendChild(box);
                Lens.lines(box, same, unit);
            };
            one('processor', document.getElementById('sysProcessor'));
            one('memory', document.getElementById('sysMemory'));
            one('temperature', document.getElementById('sysTemperature'));
        };

        Promise.all([
            $.getJSON('/api/lens/dashboard/health'),
            $.getJSON('/api/lens/system/details'),
        ]).then(([health, details]) => {
            $('#sysLoading').hide();
            Lens.healthRow(document.getElementById('healthSummary'), document.getElementById('healthTiles'), health);
            $('#healthRow').show();
            const $sections = $('#sysSections').empty();
            for (const tile of health.tiles.filter(t => t.key !== 'internet')) {
                const $s = section(tile);
                (fill[tile.key] || (() => {}))($s.find('.lens-sys-body'), details, tile, health);
                $sections.append($s);
            }
            if (location.hash) {
                const target = document.getElementById(location.hash.slice(1));
                if (target) {
                    target.scrollIntoView({ block: 'start' });
                    target.classList.add('lens-sys-target');
                }
            }
            $.getJSON('/api/lens/system/history', { hours: hours }).then(reply => draw(reply.history || {}));
        }, () => {
            $('#sysLoading').hide();
            $('#sysError').show();
        });
    });
</script>

<div class="lens-page-head">
    <div class="lens-page-intro">
        {{ lang._('This firewall, area by area: what is all right, what is not, and why. Lens reads what OPNsense already measures and keeps none of it; each section has the OPNsense page for when something has to be changed.') }}
    </div>
    <div class="lens-page-range"><span id="sysRange"></span></div>
</div>

<div id="sysLoading"><i class="fa fa-spinner fa-spin"></i> {{ lang._('Reading the firewall...') }}</div>
<div id="sysError" class="alert alert-danger" style="display: none;">{{ lang._('The system report did not come back.') }}</div>

<div id="healthRow" class="health-row" style="display: none;">
    <div id="healthSummary" class="health-summary"></div>
    <div id="healthTiles" class="health-tiles"></div>
</div>

<div id="sysSections" class="lens-sys-sections"></div>
