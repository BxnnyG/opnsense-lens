/*
 * Copyright (C) 2026 Benny <claude@bxnny.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * The five devices using the line, on the dashboard.
 *
 * It calls the same endpoint the Reporting page calls and shows the top of the
 * same list. Nothing here decides anything -- the names, the units, the
 * ordering and the staleness warning are all settled in PHP, so the widget and
 * the page cannot tell the operator different things (§4.32, §4.37).
 */
export default class Lens extends BaseTableWidget {
    constructor() {
        super();
        /* the collector writes hourly; refreshing faster only costs queries */
        this.tickTimeout = 300;
    }

    getGridOptions() {
        return { sizeToContent: 400 };
    }

    getMarkup() {
        let $container = $('<div></div>');
        $container.append(this.createTable('lensSummaryTable', { headerPosition: 'none' }));
        $container.append(this.createTable('lensDeviceTable', { headerPosition: 'none' }));
        return $container;
    }

    async onWidgetTick() {
        const report = await this.ajaxCall('/api/lens/devices/list');

        if (!report || !report.devices) {
            this.displayError(this.translations.unreachable);
            return;
        }

        if (!report.devices.length) {
            this.displayError(this.translations.nothing);
            return;
        }

        if (!this.dataChanged('lens-devices', report)) {
            return;
        }

        this.updateTable('lensSummaryTable', this.summaryRows(report));
        this.updateTable('lensDeviceTable', this.deviceRows(report));
    }

    summaryRows(report) {
        const summary = report.summary || {};
        const parts = [
            `${summary.here} / ${summary.known} ${this.translations.here}`,
            summary.moved
        ];

        /* the same rule the page follows: before Lens has watched longer than
           the window it compares against, "new" says something about the
           install and nothing about the network (§4.34) */
        if (summary.new_yet) {
            parts.push(`${summary.new.length} ${this.translations.new}`);
            if (summary.away) {
                parts.push(`${summary.away} ${this.translations.away}`);
            }
        } else if (summary.watching_for) {
            parts.push(`${summary.watching_for} ${this.translations.learning}`);
        }

        const rows = [[`<div>${parts.join(' &middot; ')}</div>`]];

        if (report.stale) {
            rows.push([`<div class="text-warning">${this.translations.stale}</div>`]);
        }

        return rows;
    }

    deviceRows(report) {
        /* already sorted heaviest first by DeviceReport; the widget does not
           re-sort, so a tie broken one way here and another way there is not
           possible */
        return report.devices.slice(0, 5).map((device) => [
            `<div><i class="fa fa-fw ${device.kind.icon}"></i> ${$('<div>').text(device.name).html()}</div>`,
            `<div class="pull-right">${device.traffic ? $('<div>').text(device.traffic).html() : ''}</div>`
        ]);
    }
}
