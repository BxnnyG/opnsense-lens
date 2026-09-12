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
    #lensWall { padding: 10px 0; }
    .wall-head { display: flex; flex-wrap: wrap; gap: 40px; align-items: flex-end; }
    .wall-big { font-size: 52px; font-weight: 600; line-height: 1; }
    .wall-label { color: #999; font-size: 14px; margin-top: 4px; }
    .wall-row { display: flex; align-items: center; gap: 14px; margin-top: 14px; }
    .wall-name { width: 16em; font-size: 20px; overflow: hidden; text-overflow: ellipsis;
                 white-space: nowrap; }
    .wall-track { flex: 1; height: 18px; background: rgba(128,128,128,0.15); border-radius: 3px; }
    .wall-fill { height: 18px; background: #d94f00; opacity: 0.75; border-radius: 3px; }
    .wall-bytes { width: 8em; text-align: right; font-size: 18px; }
    .wall-foot { margin-top: 30px; color: #999; }
    .wall-warn { color: #f0ad4e; font-weight: 600; }
    #lensWallBoard:fullscreen { background: #1b1b1b; padding: 40px; }
    #lensWallBoard:fullscreen .wall-big { font-size: 90px; }
    #lensWallBoard:fullscreen .wall-name { font-size: 28px; }
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

            const top = report.devices.slice(0, 8);
            const largest = top.reduce((max, d) => Math.max(max, d.octets), 0);

            const $rows = $('#wallRows').empty();
            for (const device of top) {
                const $bar = $('<div/>').addClass('wall-fill').css(
                    'width', largest ? Math.max(1, (device.octets / largest) * 100) + '%' : '0'
                );
                $rows.append($('<div/>').addClass('wall-row')
                    .append($('<div/>').addClass('wall-name')
                        .append($('<i/>').addClass('fa fa-fw ' + device.kind.icon))
                        .append(document.createTextNode(' ' + device.name)))
                    .append($('<div/>').addClass('wall-track').append($bar))
                    .append($('<div/>').addClass('wall-bytes')
                        .text(device.traffic ? device.traffic.split('  ')[0] : '')));
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
            $('#lensWall').show();
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
    <p>
        <a href="#" id="wallFull">{{ lang._('Fill the screen') }}</a>
        &mdash; {{ lang._('refreshes itself every minute; nothing here is clickable on purpose.') }}
    </p>

    <div id="lensWall" style="display: none;">
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
