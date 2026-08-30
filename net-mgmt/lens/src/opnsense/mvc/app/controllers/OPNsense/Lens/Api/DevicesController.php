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
use OPNsense\Lens\DeviceReport;

/**
 * Class DevicesController
 *
 * Input and output only (4.21). Three reads; DeviceReport decides what they
 * mean together.
 *
 * The vendor database is core's, read at display time rather than stored with
 * the observation: an OUI table that grows later should improve names Lens
 * already recorded, not only the ones it records next.
 *
 * @package OPNsense\Lens\Api
 */
class DevicesController extends ApiControllerBase
{
    /**
     * @return array the devices Lens has observed, named as well as it can
     */
    public function listAction()
    {
        $backend = new Backend();
        $started = microtime(true);
        $calls = [];

        $devices = self::decode($backend, 'lens devices', $calls);
        $status = self::decode($backend, 'lens status', $calls);
        $macdb = self::decode($backend, 'interface list macdb', $calls);

        $observedAt = isset($status['runs']['observe']['at'])
            ? (int)$status['runs']['observe']['at']
            : null;

        $report = DeviceReport::describe($devices, $macdb, $observedAt, time());
        $report['timing'] = [
            'total_ms' => (int)round((microtime(true) - $started) * 1000),
            'calls' => $calls,
        ];

        return $report;
    }

    /**
     * @param Backend $backend
     * @param string $command
     * @param array $calls collects what each call cost, so the page can show it
     * @return array
     */
    private static function decode(Backend $backend, string $command, array &$calls): array
    {
        $started = microtime(true);
        $raw = (string)$backend->configdRun($command);
        $calls[$command] = (int)round((microtime(true) - $started) * 1000);

        $decoded = json_decode(trim($raw), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
