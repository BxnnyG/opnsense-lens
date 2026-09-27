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
use OPNsense\Core\Config;
use OPNsense\Lens\DeviceReport;
use OPNsense\Lens\DnsDevice;
use OPNsense\Lens\DnsReport;

/**
 * Class DnsController
 *
 * Input and output only (§4.21). Behind its own privilege, Reporting: Lens:
 * DNS (§4.64): core keeps its DNS overview behind Status: DNS Overview, and a
 * user without that must not read the same questions through Lens.
 *
 * @package OPNsense\Lens\Api
 */
class DnsController extends ApiControllerBase
{
    /**
     * @return array what the network looked up, by name and by device
     */
    public function overviewAction()
    {
        $backend = new Backend();
        $started = microtime(true);
        $unbound = self::unbound();

        $raw = $unbound['enabled'] && $unbound['stats'] ? self::decode($backend, 'lens dns overview') : [];
        $report = DnsReport::describe($raw, self::rows($backend), $unbound);
        $report['now'] = time();
        $report['timing'] = ['total_ms' => (int)round((microtime(true) - $started) * 1000)];

        return $report;
    }

    /**
     * @return array what one device looked up, over a day or a week
     */
    public function deviceAction()
    {
        $macs = DeviceReport::macList((string)$this->request->get('mac', null, ''));
        if ($macs === null) {
            return ['status' => 'failed', 'message' => gettext('not a MAC address')];
        }
        $hours = (int)$this->request->get('hours', null, 24) > 24 ? 168 : 24;
        $unbound = self::unbound();

        $raw = $unbound['enabled'] && $unbound['stats']
            ? json_decode(trim((string)(new Backend())->configdpRun('lens dns device', [$macs, $hours])), true)
            : ['enabled' => true, 'asked' => 0];

        $report = DnsDevice::describe(is_array($raw) ? $raw : [], $unbound);
        $report['hours'] = $hours;

        return $report;
    }

    /** what the configuration says; SourceFacts learned the hard way it is not evidence of data (§1.6) */
    private static function unbound(): array
    {
        $general = Config::getInstance()->object()->xpath('//OPNsense/unboundplus/general');

        return [
            'enabled' => !empty($general) && (string)$general[0]->enabled === '1',
            'stats' => !empty($general) && (string)$general[0]->stats === '1',
        ];
    }

    /** every device, folded as the operator chose, so a client is named as every page names it */
    private static function rows(Backend $backend): array
    {
        $status = self::decode($backend, 'lens status');

        return DeviceReport::describe(
            self::decode($backend, 'lens devices'),
            self::decode($backend, 'interface list macdb'),
            [],
            isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null,
            time(),
            SegmentsController::names(),
            (bool)($status['fold_randomised'] ?? true)
        )['devices'];
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
