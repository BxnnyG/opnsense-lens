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
use OPNsense\Lens\Privacy;

/**
 * Class PrivacyController
 *
 * Input and output only (§4.21). What Lens keeps, and forgetting one device
 * (§4.72). Under Services: Lens, as purge is: a user who may read the reports
 * may not delete what they report on.
 *
 * @package OPNsense\Lens\Api
 */
class PrivacyController extends ApiControllerBase
{
    /**
     * @return array what is kept, per kind, and the devices one could forget
     */
    public function keptAction()
    {
        $backend = new Backend();
        $read = LensCalls::many($backend, ['status' => ['brief'], 'kept' => ['kept'], 'devices' => ['devices']]);
        $status = $read['status'];
        $report = Privacy::describe($read['kept'], time());

        $rows = DeviceReport::describe(
            $read['devices'],
            self::decode($backend, 'interface list macdb'),
            [],
            isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null,
            time(),
            [],
            (bool)($status['fold_randomised'] ?? true)
        )['devices'];

        $report['devices'] = array_map(function ($row) {
            return [
                'mac' => $row['mac'],
                'macs' => array_values((array)($row['macs'] ?? [$row['mac']])),
                'name' => $row['name'],
                'owner' => $row['owner'] ?? null,
                'here' => (bool)$row['here'],
            ];
        }, $rows);
        usort($report['devices'], function ($left, $right) {
            return strcasecmp($left['name'], $right['name']);
        });

        return $report;
    }

    /**
     * @return array what forgetting the device would delete; deletes nothing
     */
    public function previewAction()
    {
        $macs = DeviceReport::macList((string)$this->request->get('mac', null, ''));
        if ($macs === null) {
            return ['ok' => false, 'sentence' => gettext('That is not a device Lens could look up.')];
        }

        return Privacy::forgotten(self::decode(new Backend(), 'lens forget.preview ' . $macs));
    }

    /**
     * @return array what was deleted
     */
    public function forgetAction()
    {
        if (!$this->request->isPost()) {
            return ['ok' => false, 'sentence' => gettext('Forgetting a device needs a POST.')];
        }
        $macs = DeviceReport::macList((string)$this->request->getPost('mac', null, ''));
        if ($macs === null) {
            return ['ok' => false, 'sentence' => gettext('That is not a device Lens could look up.')];
        }

        return Privacy::forgotten(self::decode(new Backend(), 'lens forget.device ' . $macs));
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
