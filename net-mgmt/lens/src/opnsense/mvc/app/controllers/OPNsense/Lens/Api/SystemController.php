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
use OPNsense\Core\ACL;
use OPNsense\Core\Backend;
use OPNsense\Lens\Health;
use OPNsense\Lens\SystemDetail;
use OPNsense\Lens\SystemHistory;
use OPNsense\Lens\Window;

/**
 * Class SystemController
 *
 * Input and output only (§4.21). What stands behind the health row's tiles,
 * for Reporting: Lens: System (stage 53): the firewall's own history from
 * core's RRD files, and the lists each tile summed up. The tiles core guards
 * keep core's privilege here too (stage 50).
 *
 * @package OPNsense\Lens\Api
 */
class SystemController extends ApiControllerBase
{
    /**
     * @return array key => ['title', 'units', 'step', 'series'] for processor, memory, temperature
     */
    public function historyAction()
    {
        $backend = new Backend();
        $hours = Window::hours($this->request->get('hours', null, 24));
        $list = json_decode((string)$backend->configdRun('health list'), true);
        $out = [];
        foreach (SystemHistory::chosen(is_array($list) ? $list : []) as $key => $rrd) {
            $fetch = json_decode((string)$backend->configdpRun('health fetch', [$rrd['filename']]), true);
            $out[$key] = array_merge(
                ['title' => $rrd['title'], 'units' => $rrd['units']],
                SystemHistory::series(is_array($fetch) ? $fetch : [], $hours, time())
            );
        }

        return ['hours' => $hours, 'history' => $out];
    }

    /**
     * @return array section => its list; a section the user may not see in core is absent
     */
    public function detailsAction()
    {
        $backend = new Backend();
        $acl = new ACL();
        $user = $this->getUserName();
        $now = time();
        $out = [];

        $temperatures = DashboardController::temperatures($backend);
        if (!empty($temperatures)) {
            $out['temperature'] = [];
            foreach ($temperatures as $name => $value) {
                $out['temperature'][] = ['sensor' => Health::sensor((string)$name),
                                         'celsius' => (float)trim(str_replace('C', '', (string)$value))];
            }
        }
        if ($acl->isPageAccessible($user, '/api/core/firmware/status')) {
            $out['updates'] = SystemDetail::updates(DashboardController::decodeOrNull($backend, 'firmware product'));
        }
        if ($acl->isPageAccessible($user, '/api/core/service/search')) {
            $out['services'] = SystemDetail::services(
                (array)DashboardController::decodeOrNull($backend, 'service list')
            );
        }
        if ($acl->isPageAccessible($user, '/api/trust/cert/search')) {
            $out['certificates'] = SystemDetail::certificates(DashboardController::certificates(), $now);
        }
        if (DashboardController::installed('smart') && $acl->isPageAccessible($user, '/api/smart/service/list')) {
            $out['smart'] = SystemDetail::disks(
                (array)DashboardController::decodeOrNull($backend, 'smart detailed list')
            );
        }
        $dyndns = DashboardController::installed('ddclient')
            && $acl->isPageAccessible($user, '/api/dyndns/accounts/search_item');
        if ($dyndns) {
            $out['dyndns'] = Health::dyndnsNames(
                DashboardController::dyndnsAccounts(),
                DashboardController::decodeOrNull($backend, 'ddclient statistics')
            );
        }
        if ($acl->isPageAccessible($user, '/api/wireguard/service/show')) {
            $shown = DashboardController::decodeOrNull($backend, 'wireguard show');
            $peers = SystemDetail::wireguard(
                DashboardController::ownWireguard((array)($shown['records'] ?? [])),
                DashboardController::wireguardNames(),
                $now
            );
            if ($peers !== []) {
                $out['wireguard'] = $peers;
            }
        }

        if (DashboardController::installed('netbird') && $acl->isPageAccessible($user, '/api/netbird/status/status')) {
            $out['netbird'] = SystemDetail::netbird(
                (array)DashboardController::decodeOrNull($backend, 'netbird status-json')
            );
        }
        $tailscale = DashboardController::installed('tailscale')
            && $acl->isPageAccessible($user, '/api/tailscale/status/status');
        if ($tailscale) {
            $out['tailscale'] = SystemDetail::tailscale(
                (array)DashboardController::decodeOrNull($backend, 'tailscale tailscale-status')
            );
        }

        return $out;
    }
}
