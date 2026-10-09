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
use OPNsense\Lens\LensCalls;
use OPNsense\Lens\DeviceReport;
use OPNsense\Lens\Events;

/**
 * Class EventsController
 *
 * Input and output only (§4.21). The devices, so every event names a device
 * the way every page does, and the events themselves (§4.63).
 *
 * @package OPNsense\Lens\Api
 */
class EventsController extends ApiControllerBase
{
    /** the three ranges the page offers; anything else is the middle one */
    private const DAYS = [1, 7, 30];

    /**
     * @return array what happened over the chosen range, newest first
     */
    public function listAction()
    {
        $days = (int)$this->request->get('days', null, 7);
        if (!in_array($days, self::DAYS, true)) {
            $days = 7;
        }

        $backend = new Backend();
        $started = microtime(true);
        $read = LensCalls::many($backend, [
            'status' => ['brief'], 'devices' => ['devices'], 'events' => ['events', ['days' => $days]],
        ]);
        $status = $read['status'];
        $observedAt = isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null;
        $names = SegmentsController::names();

        $devices = DeviceReport::describe(
            $read['devices'],
            self::decode($backend, 'interface list macdb'),
            [],
            $observedAt,
            time(),
            $names,
            (bool)($status['fold_randomised'] ?? true)
        );

        $report = Events::describe($read['events'], $devices['devices'], $names, time());
        $report['timing'] = ['total_ms' => (int)round((microtime(true) - $started) * 1000)];

        return $report;
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
