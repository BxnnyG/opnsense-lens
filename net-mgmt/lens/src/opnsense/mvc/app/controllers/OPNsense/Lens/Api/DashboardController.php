<?php

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

namespace OPNsense\Lens\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Lens\Bytes;
use OPNsense\Core\Config;
use OPNsense\Lens\Heatmap;
use OPNsense\Lens\Internet;
use OPNsense\Lens\LineQuality;
use OPNsense\Lens\SystemFacts;
use OPNsense\Lens\Window;

/**
 * Class DashboardController
 *
 * Input and output only (§4.21). Two things the other pages do not already
 * provide: the network over time, and the firewall's own vital signs.
 *
 * The vital signs are read here through configd rather than by calling core's
 * own `api/diagnostics/*` from the browser. Those endpoints sit behind core's
 * privileges, not Lens's, so a user holding exactly Reporting: Lens would get a
 * dashboard with a blank system card -- §4.38's failure, arriving by a
 * different road.
 *
 * @package OPNsense\Lens\Api
 */
class DashboardController extends ApiControllerBase
{
    /**
     * @return array traffic on the operator's own segments over the window
     */
    public function timelineAction()
    {
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));
        $raw = self::decode(new Backend(), 'lens timeline ' . $hours);

        $series = [];
        $peak = 0;
        foreach ($raw['series'] ?? [] as $point) {
            $sent = (int)($point['sent'] ?? 0);
            $received = (int)($point['received'] ?? 0);
            $peak = max($peak, $sent + $received);
            $series[] = ['at' => (int)($point['at'] ?? 0), 'sent' => $sent, 'received' => $received];
        }

        return [
            'series' => $series,
            'step' => (int)($raw['step'] ?? 3600),
            'peak' => $peak,
            'peak_text' => Bytes::human($peak),
            'window' => Window::describe(
                $hours,
                isset($raw['first_bucket']) ? (int)$raw['first_bucket'] : null,
                time()
            ),
        ];
    }

    /**
     * @return array the internet: state, WAN addresses, public round trips, uptime
     */
    public function internetAction()
    {
        $backend = new Backend();
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        $wan = Config::getInstance()->object()->interfaces->wan ?? null;
        $wanName = $wan !== null && (string)$wan->descr !== '' ? (string)$wan->descr : 'WAN';

        return Internet::describe(
            self::decode($backend, 'interface address'),
            self::decode($backend, 'lens internet ' . $hours),
            LineQuality::describe(self::decode($backend, 'interface gateways status'), []),
            $wanName,
            time()
        );
    }

    /**
     * @return array every gateway's quality now, and over the chosen range
     */
    public function lineAction()
    {
        $backend = new Backend();
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        return LineQuality::describe(
            self::decode($backend, 'interface gateways status'),
            self::decode($backend, 'lens gateways ' . $hours)
        );
    }

    /**
     * @return array the network's week, hour by hour
     */
    public function heatmapAction()
    {
        $raw = self::decode(new Backend(), 'lens heatmap');
        $grid = Heatmap::grid((array)($raw['heatmap'] ?? []));
        $grid['heatmap_days'] = (int)($raw['heatmap_days'] ?? 28);

        return $grid;
    }

    /**
     * @return array load, memory, disk, uptime, and the WAN counters
     */
    public function systemAction()
    {
        $backend = new Backend();

        /* configdpRun, as core itself calls it -- the list is one parameter */
        $sysctl = json_decode(
            trim((string)$backend->configdpRun('system sysctl values', [implode(',', SystemFacts::SYSCTLS)])),
            true
        );

        return SystemFacts::assemble(
            is_array($sysctl) ? $sysctl : [],
            self::decode($backend, 'system diag disk'),
            self::decode($backend, 'interface show traffic'),
            time()
        );
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
