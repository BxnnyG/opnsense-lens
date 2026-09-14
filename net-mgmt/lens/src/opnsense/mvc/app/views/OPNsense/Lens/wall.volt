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

<style>
    /*
     * The board fills the height it is given rather than sitting in the top
     * eighth of it. A wall display that leaves two thirds of the screen empty
     * reads as broken from across the room, which is the only distance that
     * matters for this page.
     */
    #lensWallBoard { display: flex; flex-direction: column;
                     min-height: calc(100vh - 190px); }
    #lensWall { display: none; flex: 1; flex-direction: column; }
    #lensWall.wall-on { display: flex; }

    .wall-head { display: flex; flex-wrap: wrap; gap: 5vw; align-items: flex-end;
                 margin-bottom: 2vh; }
    .wall-big { font-size: clamp(38px, 5vw, 86px); font-weight: 600; line-height: 1; }
    .wall-label { color: #999; font-size: clamp(12px, 1vw, 18px); margin-top: 4px; }

    #wallRows { flex: 1; display: flex; flex-direction: column;
                justify-content: space-evenly; gap: 4px; }
    .wall-row { display: flex; align-items: center; gap: 1.5vw; }
    .wall-name { width: 22%; min-width: 10em;
                 font-size: clamp(14px, 1.5vw, 30px);
                 overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wall-track { flex: 1; height: clamp(14px, 2.2vh, 34px);
                  background: rgba(128, 128, 128, 0.15); border-radius: 3px; }
    .wall-fill { height: 100%; background: #d94f00; opacity: 0.8; border-radius: 3px; }
    .wall-bytes { width: 6em; text-align: right;
                  font-size: clamp(14px, 1.4vw, 28px); font-variant-numeric: tabular-nums; }

    .wall-foot { margin-top: 2vh; color: #999; }
    .wall-warn { color: #f0ad4e; font-weight: 600; }

    #lensWallBoard:fullscreen { min-height: 100vh; padding: 3vh 3vw; }
</style>

<script>
    $(document).ready(() => {
        /*
         * Every number here comes from the endpoint the Reporting page and the
         * dashboard widget call (§4.37). A wall display that disagrees with the
         * page is the worst of the three, because nobody is standing at it to
         * notice.
         */
        const REFRESH = 60000;

        /*
         * Eight rows reading "Proxmox Server Solutions GmbH..." are eight rows
         * saying nothing. The Devices page already folds a herd into one entry
         * and hands the groups over; the board uses them rather than inventing
         * its own rule, so the two cannot disagree about what a group is.
         */
        const fold = (report) => {
            const groups = new Map((report.groups || []).map(g => [g.key, g]));
            const folded = new Map();
            const rows = [];

            for (const device of report.devices) {
                const group = groups.get(device.group);
                if (!group) {
                    rows.push({ name: device.name, icon: device.kind.icon,
                                octets: device.octets });
                    continue;
                }
                if (!folded.has(group.key)) {
                    folded.set(group.key, { name: group.label + ' \u00d7 ' + group.count,
                                            icon: group.icon, octets: 0 });
                    rows.push(folded.get(group.key));
                }
                folded.get(group.key).octets += device.octets;
            }

            return rows.sort((left, right) => right.octets - left.octets);
        };

        const bytes = (octets) => {
            if (!octets) {
                return '';
            }
            if (octets < 1024) {
                return octets + ' B';
            }
            const units = ['KB', 'MB', 'GB', 'TB'];
            let value = octets / 1024;
            for (let i = 0; i < units.length; i++) {
                if (value < 1024 || i === units.length - 1) {
                    return (value < 10 ? value.toFixed(1) : Math.round(value)) + ' ' + units[i];
                }
                value /= 1024;
            }
        };

        const render = (report) => {
            const summary = report.summary || {};

            $('#wallHere').text(summary.here);
            $('#wallKnown').text(summary.known);
            $('#wallMoved').text(summary.moved || '0 B');

            if (summary.new_yet) {
                $('#wallNew').text(summary.new.length);
                $('#wallNewLabel').text('{{ lang._("new in 24 hours") }}');
            } else {
                $('#wallNew').text(summary.watching_for || '');
                $('#wallNewLabel').text('{{ lang._("watching so far") }}');
            }

            /* how many rows fit, rather than a number picked at the desk */
            const room = Math.floor(($('#wallRows').height() || 320) / 44);
            const top = fold(report).slice(0, Math.max(5, Math.min(14, room)));
            const largest = top.reduce((max, e) => Math.max(max, e.octets), 0);

            const $rows = $('#wallRows').empty();
            for (const entry of top) {
                const $bar = $('<div/>').addClass('wall-fill').css(
                    'width', largest ? Math.max(1, (entry.octets / largest) * 100) + '%' : '0'
                );
                $rows.append($('<div/>').addClass('wall-row')
                    .append($('<div/>').addClass('wall-name').attr('title', entry.name)
                        .append($('<i/>').addClass('fa fa-fw ' + entry.icon))
                        .append(document.createTextNode(' ' + entry.name)))
                    .append($('<div/>').addClass('wall-track').append($bar))
                    .append($('<div/>').addClass('wall-bytes').text(bytes(entry.octets))));
            }

            /* the one thing a wall display must never do is look current while
               being hours old -- nobody is there to wonder (§4.22) */
            $('#wallStale').toggleClass('wall-warn', !!report.stale)
                .text(report.stale ? (report.note || '') : '');
            $('#wallAt').text(new Date().toLocaleTimeString());
        };

        const load = () => ajaxGet('/api/lens/devices/list', {}, (report, status) => {
            if (status !== 'success' || !report || !report.devices) {
                $('#wallStale').addClass('wall-warn')
                    .text('{{ lang._("Lens did not answer. This screen is not current.") }}');
                return;
            }
            $('#lensWall').addClass('wall-on');
            render(report);
        });

        $('#wallFull').on('click', (event) => {
            event.preventDefault();
            const board = document.getElementById('lensWallBoard');
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (board.requestFullscreen) {
                board.requestFullscreen();
            }
        });

        load();
        setInterval(load, REFRESH);
    });
</script>

<div id="lensWallBoard">
    <p class="text-muted">
        <a href="#" id="wallFull">{{ lang._('Fill the screen') }}</a>
        &mdash; {{ lang._('refreshes itself every minute; nothing here is clickable on purpose.') }}
    </p>

    <div id="lensWall">
        <div class="wall-head">
            <div>
                <div class="wall-big"><span id="wallHere"></span> / <span id="wallKnown"></span></div>
                <div class="wall-label">{{ lang._('devices here now') }}</div>
            </div>
            <div>
                <div class="wall-big" id="wallMoved"></div>
                <div class="wall-label">{{ lang._('in the last 24 hours') }}</div>
            </div>
            <div>
                <div class="wall-big" id="wallNew"></div>
                <div class="wall-label" id="wallNewLabel"></div>
            </div>
        </div>

        <div id="wallRows"></div>

        <div class="wall-foot">
            <span id="wallStale"></span>
            <span class="pull-right">{{ lang._('updated') }} <span id="wallAt"></span></span>
        </div>
    </div>
</div>
