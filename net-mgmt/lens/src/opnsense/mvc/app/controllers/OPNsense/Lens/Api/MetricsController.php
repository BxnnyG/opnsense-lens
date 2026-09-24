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
use OPNsense\Lens\BaselineReport;
use OPNsense\Lens\DeviceReport;
use OPNsense\Lens\Metrics;
use OPNsense\Lens\SegmentReport;

/**
 * Class MetricsController
 *
 * Prometheus text format, so a Grafana user gets Lens inside the tool they
 * already use. Input and output only (§4.21): every number is decided by the
 * same pure reports the pages use.
 *
 * Scrape it with an OPNsense API key belonging to a user who holds Reporting:
 * Lens -- Prometheus sends it as basic auth:
 *
 *     - job_name: lens
 *       scheme: https
 *       metrics_path: /api/lens/metrics/prometheus
 *       basic_auth: { username: <key>, password: <secret> }
 *       static_configs: [ { targets: [ 'firewall.example:443' ] } ]
 *
 * @package OPNsense\Lens\Api
 */
class MetricsController extends ApiControllerBase
{
    public function prometheusAction()
    {
        $backend = new Backend();

        $devices = self::decode($backend, 'lens devices');
        $status = self::decode($backend, 'lens status');
        $macdb = self::decode($backend, 'interface list macdb');
        $traffic = self::decode($backend, 'lens traffic 24');

        $observedAt = isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null;

        $report = DeviceReport::describe($devices, $macdb, $traffic, $observedAt, time());

        $names = [];
        foreach ($report['devices'] as $row) {
            $names[$row['mac']] = $row['name'];
        }
        $report['baseline'] = BaselineReport::describe(self::decode($backend, 'lens baseline'), $names);

        $segments = SegmentReport::describe(self::decode($backend, 'lens segments 24'), SegmentsController::names());

        /* the exposition format, not JSON: Prometheus reads nothing else */
        $this->response->setRawHeader('Content-Type: text/plain; version=0.0.4; charset=utf-8');

        return Metrics::render($report, $segments, $status, time());
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
