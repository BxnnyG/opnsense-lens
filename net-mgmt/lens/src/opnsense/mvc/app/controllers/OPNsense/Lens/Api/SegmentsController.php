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
use OPNsense\Lens\SegmentReport;
use OPNsense\Lens\Window;

/**
 * Class SegmentsController
 *
 * Input and output only (§4.21). Two reads: the store's own totals, and the
 * config, which is the only place that knows `lagg0_vlan24` is called Server.
 *
 * @package OPNsense\Lens\Api
 */
class SegmentsController extends ApiControllerBase
{
    private const DEFAULT_HOURS = 24;
    private const MAX_HOURS = 24 * 366;

    /**
     * @return array traffic per network, and how much of it has a device
     */
    public function listAction()
    {
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        $started = microtime(true);
        $raw = json_decode(trim((string)(new Backend())->configdRun('lens segments ' . $hours)), true);

        $report = SegmentReport::describe(
            json_last_error() === JSON_ERROR_NONE && is_array($raw) ? $raw : [],
            self::names()
        );
        $report['window'] = Window::describe(
            $hours,
            isset($raw['first_bucket']) ? (int)$raw['first_bucket'] : null,
            time()
        );
        $report['timing'] = ['total_ms' => (int)round((microtime(true) - $started) * 1000)];

        return $report;
    }

    /**
     * The store records the device name flowd reported; only the configuration
     * knows what a person calls it.
     *
     * @return array device name to description
     */
    public static function names(): array
    {
        $names = [];

        foreach (Config::getInstance()->object()->interfaces->children() as $key => $interface) {
            $device = (string)$interface->if;
            $description = (string)$interface->descr;

            if ($device !== '' && $device !== $key) {
                $names[$device] = $description !== '' ? $description : strtoupper((string)$key);
            }
        }

        return $names;
    }
}
