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
     * Who pausing a group would pause, and who it leaves out and why -- shown
     * before the click, as everything Lens writes is (§4.5, §4.94).
     *
     * @return array
     */
    public function groupAction()
    {
        $backend = new Backend();
        $rows = self::rows($backend, $names);
        $group = Pause::group(
            (string)$this->request->get('kind', null, ''),
            (string)$this->request->get('name', null, ''),
            $rows,
            self::status($backend),
            $this->clientAddress(),
            self::protectedNetworks($backend),
            $names
        );
        if ($group === null) {
            return ['status' => 'failed', 'message' => gettext('A group is a tag or an owner.')];
        }

        return ['status' => 'ok', 'members' => $group['members'],
                'pause' => array_column($group['pause'], 'name'),
                'already' => $group['already'], 'refused' => $group['refused']];
    }

    /**
     * Every device of a tag or an owner at once: one change to the alias, one
     * reload, and one record per device -- so each ends on its own time and
     * can be resumed alone (BACKLOG #52, §4.94).
     *
     * @return array
     */
    public function pauseGroupAction()
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
        $kind = (string)$this->request->getPost('kind', null, '');
        $backend = new Backend();
        $rows = self::rows($backend, $names);
        $group = Pause::group(
            $kind,
            (string)$this->request->getPost('name', null, ''),
            $rows,
            self::status($backend),
            $this->clientAddress(),
            self::protectedNetworks($backend),
            $names
        );
        if ($group === null) {
            return ['status' => 'failed', 'message' => gettext('A group is a tag or an owner.')];
        }
        if ($group['pause'] === []) {
            return ['status' => 'refused', 'message' => gettext('No device of this group can be paused from here.'),
                    'already' => $group['already'], 'refused' => $group['refused']];
        }

        $reason = Pause::reason((string)$this->request->getPost('reason', null, ''));
        $open = (array)(self::decode($backend, 'lens pauses')['open'] ?? []);
        $macs = [];
        foreach ($group['pause'] as $row) {
            $macs = array_merge($macs, (array)$row['macs']);
        }
        $before = PauseRule::addresses();
        $changed = PauseRule::change(
            $macs,
            [],
            /* a count and the kind, never a device's or a person's name, in
               core's configuration history (edge case 6); a tag is the
               operator's own word, as the reason is */
            ($kind === 'tag'
                ? sprintf(gettext('Lens: paused %d devices tagged %s'), count($group['pause']), trim(
                    (string)$this->request->getPost('name', null, '')
                ))
                : sprintf(gettext('Lens: paused %d devices of one owner'), count($group['pause'])))
            . ($reason !== '' ? ': ' . $reason : ''),
            Pause::reasonsAfter($open, [], $reason)
        );
        if (!$changed['ok']) {
            return ['status' => 'failed', 'message' => $changed['message']];
        }
        $dropped = PauseRule::dropStates(array_values(array_diff(PauseRule::addresses(), $before)));

        $until = Pause::until($minutes, time());
        $unrecorded = [];
        foreach ($group['pause'] as $row) {
            $recorded = trim((string)$backend->configdpRun(
                'lens pause.start',
                [$row['mac'], implode(',', $row['macs']), (string)($until ?? 0), Pause::reasonParameter($reason)]
            )) === 'started';
            if (!$recorded) {
                $unrecorded[] = (string)$row['name'];
            }
        }

        return [
            'status' => 'ok',
            'until' => $until,
            'paused' => array_column($group['pause'], 'name'),
            'already' => $group['already'],
            'refused' => $group['refused'],
            'created' => $changed['created'],
            'dropped' => $dropped,
            'message' => $unrecorded === [] ? null : sprintf(
                gettext('Paused, but Lens could not note it for %s: those will not end on their own.'),
                implode(', ', $unrecorded)
            ),
        ];
    }

    /**
     * Every paused device of a group back at once; resuming is always allowed.
     *
     * @return array
     */
    public function resumeGroupAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }
        $backend = new Backend();
        $members = Pause::members(
            (string)$this->request->getPost('kind', null, ''),
            (string)$this->request->getPost('name', null, ''),
            self::rows($backend, $names)
        );
        if ($members === null) {
            return ['status' => 'failed', 'message' => gettext('A group is a tag or an owner.')];
        }
        $open = (array)(self::decode($backend, 'lens pauses')['open'] ?? []);
        $resume = Pause::resumeGroup($members, $open);
        if ($resume['keys'] === []) {
            return ['status' => 'ok', 'resumed' => 0];
        }
        $changed = PauseRule::change(
            [],
            $resume['macs'],
            sprintf(gettext('Lens: resumed %d devices'), count($resume['keys'])),
            Pause::reasonsAfter($open, $resume['keys'])
        );
        if (!$changed['ok']) {
            return ['status' => 'failed', 'message' => $changed['message']];
        }
        foreach ($resume['keys'] as $key) {
            $backend->configdpRun('lens pause.end', [$key, 'resumed']);
        }

        return ['status' => 'ok', 'resumed' => count($resume['keys'])];
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
        $names = [];
        $macs = DeviceReport::macs($raw);
        if ($macs === null) {
            return null;
        }

        $rows = self::rows($backend, $names);
        foreach ($rows as $row) {
            if (in_array($macs[0], $row['macs'], true)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Every device row, folded as every page folds it (§4.61).
     */
    private static function rows(Backend $backend, &$names): array
    {
        $names = SegmentsController::names();
        $read = LensCalls::many($backend, ['status' => ['brief'], 'devices' => ['devices']]);
        $status = $read['status'];

        return DeviceReport::describe(
            $read['devices'],
            self::decode($backend, 'interface list macdb'),
            [],
            isset($status['runs']['observe']['at']) ? (int)$status['runs']['observe']['at'] : null,
            time(),
            $names,
            (bool)($status['fold_randomised'] ?? true)
        )['devices'];
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
