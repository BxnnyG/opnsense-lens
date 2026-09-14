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
use OPNsense\Lens\DeviceDetail;
use OPNsense\Lens\DeviceReport;
use OPNsense\Lens\Window;

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
    /** the store keeps a year; asking for all of it by accident should not be possible */
    private const DEFAULT_HOURS = 24;
    private const MAX_HOURS = 24 * 366;

    /**
     * @return array the devices Lens has observed, named as well as it can
     */
    public function listAction()
    {
        $backend = new Backend();
        $started = microtime(true);
        $calls = [];

        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        $devices = self::decode($backend, 'lens devices', $calls);
        $status = self::decode($backend, 'lens status', $calls);
        $macdb = self::decode($backend, 'interface list macdb', $calls);
        $traffic = self::decode($backend, 'lens traffic ' . $hours, $calls);

        $observedAt = isset($status['runs']['observe']['at'])
            ? (int)$status['runs']['observe']['at']
            : null;

        $report = DeviceReport::describe($devices, $macdb, $traffic, $observedAt, time());
        $report['window'] = Window::describe(
            $hours,
            isset($traffic['first_bucket']) ? (int)$traffic['first_bucket'] : null,
            time()
        );
        $report['timing'] = [
            'total_ms' => (int)round((microtime(true) - $started) * 1000),
            'calls' => $calls,
        ];

        return $report;
    }

    /**
     * One device's hourly history.
     *
     * @return array
     */
    public function historyAction()
    {
        $mac = (string)$this->request->get('mac', null, '');
        if (!preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac)) {
            return ['status' => 'failed', 'message' => gettext('not a MAC address')];
        }

        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        $calls = [];
        $raw = self::decode(new Backend(), 'lens device ' . $mac . ' ' . $hours, $calls);

        $detail = DeviceDetail::describe($raw, time());
        $detail['timing'] = ['calls' => $calls];

        return $detail;
    }

    /**
     * What the operator calls one device. The only write in the plugin, and it
     * goes into Lens's own store -- never into the firewall's configuration.
     *
     * @return array
     */
    public function labelAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }

        $mac = (string)$this->request->getPost('mac', null, '');
        if (!preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac)) {
            return ['status' => 'failed', 'message' => gettext('not a MAC address')];
        }

        $fields = [];
        foreach (['name', 'kind', 'tags', 'note'] as $field) {
            $fields[$field] = (string)$this->request->getPost($field, null, '');
        }

        /* base64url, so free text a person typed never has to survive a trip
           through configd's parameter list as punctuation */
        $encoded = rtrim(strtr(base64_encode(json_encode($fields)), '+/', '-_'), '=');

        $reply = trim((string)(new Backend())->configdpRun('lens label', [$mac, $encoded]));

        return in_array($reply, ['saved', 'cleared'], true)
            ? ['status' => 'ok', 'result' => $reply]
            : ['status' => 'failed', 'message' => $reply];
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
