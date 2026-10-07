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
 * Which services the network used today, on core's own dashboard (#44,
 * §4.82): the services someone chose, not what devices ask by themselves,
 * each with how many devices asked and a bar against the busiest. Read from
 * the DNS page's own endpoint, behind its own privilege (§4.64); the limits
 * are one link away, on that page.
 */
export default class LensServices extends BaseTableWidget {
    constructor() {
        super();
        /* reading Unbound's day takes a second or two; once in ten minutes is plenty */
        this.tickTimeout = 600;
    }

    getGridOptions() {
        return { sizeToContent: 350 };
    }

    getMarkup() {
        return $('<div></div>')
            .append(this.createTable('lensServicesTable', { headerPosition: 'none' }))
            .append($(`<div style="padding: 2px 8px;"><a href="/ui/lens/dns">${this.translations.open} &rsaquo;</a></div>`));
    }

    async onWidgetTick() {
        /* a refusal (no privilege, a timeout) rejects; it is said, not thrown */
        let report = null;
        try {
            report = await this.ajaxCall('/api/lens/dns/overview');
        } catch (failure) {
            report = null;
        }
        if (!report || !report.state) {
            this.displayError(this.translations.unreachable);
            return;
        }
        if (report.state.key !== 'ok') {
            this.displayError(report.state.text);
            return;
        }
        if (!this.dataChanged('lens-services', report.services)) {
            return;
        }
        const text = (value) => $('<div/>').text(String(value)).html();
        const chosen = ((report.services || {}).groups || [])
            .filter(group => group.kind !== 'platform')
            .flatMap(group => group.services)
            .sort((a, b) => b.devices - a.devices || b.bar - a.bar)
            .slice(0, 8);
        if (!chosen.length) {
            this.displayError(report.quiet || this.translations.nothing);
            return;
        }
        const top = Math.max(...chosen.map(s => s.bar), 1);
        this.updateTable('lensServicesTable', chosen.map(service => [
            `<div><i class="fa fa-fw ${text(service.icon)}"></i> ${text(service.name)}`
            + `<div style="height: 3px; margin-top: 3px; border-radius: 2px; background: #d94f00; opacity: 0.7;`
            + ` width: ${Math.max(3, service.bar / top * 100)}%;"></div></div>`,
            `<div class="pull-right text-muted">${text(service.sub)}</div>`
        ]));
    }
}
