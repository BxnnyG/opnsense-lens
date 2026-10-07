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
 * The internet, on core's own dashboard (§4.82): online or not, the address
 * the world sees, the round trips to the public resolvers, and the day's
 * uptime strip with the same hover the Lens dashboard has. Everything is
 * settled in PHP by the endpoint the Lens dashboard calls, so the two cannot
 * disagree (§4.32).
 */
export default class LensInternet extends BaseWidget {
    constructor() {
        super();
        /* the probes run every five minutes */
        this.tickTimeout = 120;
    }

    getGridOptions() {
        return { sizeToContent: 300 };
    }

    getMarkup() {
        /* fixed ids, as core's own widgets use: there is one of each on a dashboard */
        return $(`<div style="padding: 4px 8px;">
            <div><span id="lensw-net-dot"></span><b id="lensw-net-state"></b>
                <a class="pull-right" href="/ui/lens/dashboard">${this.translations.open} &rsaquo;</a></div>
            <div id="lensw-net-addr" style="margin-top: 6px;"></div>
            <div id="lensw-net-probes" style="margin-top: 6px;"></div>
            <div id="lensw-net-strip"></div>
            <div id="lensw-net-sub" class="text-muted"></div>
        </div>`);
    }

    async onWidgetTick() {
        /* a refusal (no privilege, a timeout) rejects; it is said, not thrown */
        let net = null;
        try {
            net = await this.ajaxCall('/api/lens/dashboard/internet');
        } catch (failure) {
            net = null;
        }
        if (!net || !net.state) {
            this.displayError(this.translations.unreachable);
            return;
        }
        if (!this.dataChanged('lens-internet', net)) {
            return;
        }
        const text = (value) => $('<div/>').text(value === null || value === undefined ? '' : String(value)).html();
        const colour = { up: '#3c763d', degraded: '#f0ad4e', down: '#d9534f' }[net.state.key] || '#999';
        $('#lensw-net-dot').css({ display: 'inline-block', width: '10px', height: '10px', 'border-radius': '50%',
                                     background: colour, 'margin-right': '6px' });
        $('#lensw-net-state').text(net.state.text);

        const lines = [];
        const pub = net.public;
        if (pub && pub.relation && pub.relation.key !== 'same' && (pub.ipv4 || pub.ipv6)) {
            lines.push(`${this.translations.public} <b>${text(pub.ipv4 || pub.ipv6)}</b>`
                       + (pub.relation.key === 'cgnat' ? ' (CGNAT)' : ''));
        }
        if (net.wan && (net.wan.ipv4 || net.wan.ipv6)) {
            lines.push(`${text(net.wan.name)} <b>${text(net.wan.ipv4 || net.wan.ipv6)}</b>`
                       + (pub && pub.relation && pub.relation.key === 'same' ? ` (${this.translations.public})` : ''));
        }
        $('#lensw-net-addr').html(lines.join('<br>'));

        $('#lensw-net-probes').html((net.probes || []).map((probe) =>
            `<span style="margin-right: 12px;">${text(probe.target)} <b>${probe.rtt === null
                ? (probe.loss === null ? '&mdash;' : this.translations.noanswer) : Math.round(probe.rtt) + ' ms'}</b></span>`
        ).join(''));

        const states = { up: '#5cb85c', partial: '#f0ad4e', down: '#d9534f', none: 'rgba(128,128,128,0.25)' };
        const uptime = net.uptime || {};
        const $strip = $('#lensw-net-strip').empty()
            .css({ display: 'flex', gap: '1px', height: '14px', margin: '8px 0 4px' });
        (uptime.strip || []).forEach((state, i) => {
            const slot = (uptime.slots || [])[i];
            const title = slot ? `${new Date(slot[0] * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                + ` – ${new Date(slot[1] * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                + (slot[2] ? `: ${slot[2] - slot[3]}/${slot[2]} ${this.translations.answered}` : '')
                + (slot[4] !== null ? `, ${slot[4]} ms` : '') : state;
            $('<span/>').css({ flex: 1, 'border-radius': '2px', background: states[state] || states.none })
                .attr('title', title).appendTo($strip);
        });
        $('#lensw-net-sub').text(uptime.percent === null || uptime.percent === undefined ? ''
            : `${this.translations.uptime} ${uptime.percent}%` + (uptime.last
                ? ` · ${this.translations.lastoutage} ${uptime.last.ago}, ${uptime.last.for}` : ''));
    }
}
