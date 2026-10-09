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
use OPNsense\Lens\Pause;
use OPNsense\Lens\PauseRule;

/**
 * Class PauseController
 *
 * Input and output only (§4.21): Pause decides, PauseRule writes. Its own
 * privilege, Services: Lens: Pause devices -- reading what a device did is
 * not the same as cutting it off (§4.74).
 *
 * The browser sends a MAC and a duration and nothing else. Which device that
 * is, which MACs it stands for and whether it may be paused are worked out
 * here again, from the box's own state (§4.35).
 *
 * @package OPNsense\Lens\Api
 */
class PauseController extends ApiControllerBase
{
    /**
     * Everything the firewall and the store say about pauses.
     *
     * @return array
     */
    public function statusAction()
    {
        return self::status(new Backend());
    }

    /**
     * One device's pause, and whether this click may pause it.
     *
     * @return array
     */
    public function deviceAction()
    {
        $backend = new Backend();
        $target = self::target($backend, (string)$this->request->get('mac', null, ''), $rows, $names);
        if ($target === null) {
            return ['status' => 'failed', 'message' => gettext('Lens has never seen this device.')];
        }

        $status = self::status($backend);
        $protected = self::protectedNetworks($backend);

        return [
            'status' => 'ok',
            'pause' => Pause::ofDevice($target, $status),
            'refused' => Pause::refuse($target, $rows, $this->clientAddress(), $protected, $names),
            'rule' => $status['rule'],
            /* nothing ticked: only the network of this click is protected (§4.83) */
            'guarded' => array_values(array_map(function ($interface) use ($names) {
                return $names[$interface] ?? $interface;
            }, $protected)),
        ];
    }

    /**
     * @return array
     */
    public function pauseAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }

        $minutes = Pause::minutes($this->request->getPost('minutes', null, ''));
        if ($minutes === null) {
            return ['status' => 'failed', 'message' => sprintf(
                gettext('A pause lasts from 1 minute to %d, or until resumed.'),
                Pause::MAX_MINUTES
            )];
        }

        $backend = new Backend();
        $target = self::target($backend, (string)$this->request->getPost('mac', null, ''), $rows, $names);
        if ($target === null) {
            return ['status' => 'failed', 'message' => gettext('Lens has never seen this device.')];
        }

        $refused = Pause::refuse($target, $rows, $this->clientAddress(), self::protectedNetworks($backend), $names);
        if ($refused !== null) {
            return ['status' => 'refused', 'message' => $refused];
        }

        $reason = Pause::reason((string)$this->request->getPost('reason', null, ''));
        $open = (array)(self::decode($backend, 'lens pauses')['open'] ?? []);
        $before = PauseRule::addresses();
        $changed = PauseRule::change(
            $target['macs'],
            [],
            /* the MAC, not the name: a hostname is the device's own word, and
               core's configuration history is no place for it (edge case 6);
               the reason is the operator's own */
            sprintf(gettext('Lens: paused %s'), $target['mac']) . ($reason !== '' ? ': ' . $reason : ''),
            Pause::reasonsAfter($open, [$target['mac']], $reason)
        );
        if (!$changed['ok']) {
            return ['status' => 'failed', 'message' => $changed['message']];
        }
        $dropped = PauseRule::dropStates(array_values(array_diff(PauseRule::addresses(), $before)));

        $until = Pause::until($minutes, time());
        $recorded = trim((string)$backend->configdpRun(
            'lens pause.start',
            [$target['mac'], implode(',', $target['macs']), (string)($until ?? 0), Pause::reasonParameter($reason)]
        )) === 'started';

        return [
            'status' => 'ok',
            'until' => $until,
            'created' => $changed['created'],
            'dropped' => $dropped,
            /* paused either way -- the alias decides -- but without the record
               a timed pause would not end on its own, and the page says so */
            'recorded' => $recorded,
            'message' => $recorded ? null : gettext(
                'The device is paused, but Lens could not note it: it will not end on its own. Resume it here.'
            ),
        ];
    }

    /**
     * Resuming is always allowed: it only ever gives a device back.
     *
     * @return array
     */
    public function resumeAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }

        $macs = DeviceReport::macs((string)$this->request->getPost('mac', null, ''));
        if ($macs === null) {
            return ['status' => 'failed', 'message' => gettext('not a MAC address')];
        }

        $backend = new Backend();
        $target = self::target($backend, $macs[0], $rows, $names);
        $open = (array)(self::decode($backend, 'lens pauses')['open'] ?? []);
        $resume = Pause::resumeSet($macs, $target, $open);

        $changed = PauseRule::change(
            [],
            $resume['macs'],
            sprintf(gettext('Lens: resumed %s'), $macs[0]),
            Pause::reasonsAfter($open, $resume['keys'])
        );
        if (!$changed['ok']) {
            return ['status' => 'failed', 'message' => $changed['message']];
        }
        foreach ($resume['keys'] as $key) {
            $backend->configdpRun('lens pause.end', [$key, 'resumed']);
        }

        return ['status' => 'ok'];
    }

    /**
     * The alias and the store together; an open pause whose MACs left the
     * alias outside Lens is closed here, so the store does not say "paused"
     * about a device that is not.
     */
    private static function status(Backend $backend): array
    {
        $firewall = PauseRule::read();
        $open = (array)(self::decode($backend, 'lens pauses')['open'] ?? []);
        $status = Pause::status(Pause::macsIn((string)($firewall['content'] ?? '')), $open, $firewall['rule']);

        foreach ($status['stale'] as $key) {
            $backend->configdpRun('lens pause.end', [$key, 'outside']);
        }
        $status['alias'] = $firewall['content'] !== null;
        $status['status'] = 'ok';

        return $status;
    }

    /**
     * The device row for a MAC, folded as every page folds it (§4.61).
     */
    private static function target(Backend $backend, string $raw, &$rows, &$names): ?array
    {
        $rows = [];
        $names = SegmentsController::names();
        $macs = DeviceReport::macs($raw);
        if ($macs === null) {
            return null;
        }

        $read = LensCalls::many($backend, ['status' => ['brief'], 'devices' => ['devices']]);
        $status = $read['status'];
        $rows = DeviceReport::describe(
            $read['devices'],
            self::decode($backend, 'interface list macdb'),
            [],
            isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null,
            time(),
            $names,
            (bool)($status['fold_randomised'] ?? true)
        )['devices'];

        foreach ($rows as $row) {
            if (in_array($macs[0], $row['macs'], true)) {
                return $row;
            }
        }

        return null;
    }

    private static function protectedNetworks(Backend $backend): array
    {
        return (array)(self::decode($backend, 'lens settings')['values']['pause_protected'] ?? []);
    }

    private function clientAddress(): string
    {
        return (string)$this->request->getClientAddress();
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return is_array($decoded) ? $decoded : [];
    }
}
