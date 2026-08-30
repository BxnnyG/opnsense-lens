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

namespace OPNsense\Lens;

/**
 * Class DeviceReport
 *
 * Observations to devices. Pure: raw store rows in, display rows out, no I/O
 * and no clock of its own (4.21).
 *
 * Two things here are decisions, not formatting.
 *
 * 1. **A device holds a set of addresses.** Confirmed on the operator's network
 *    2026-08-30: one admin PC sits in MGNT and HOME with an address in each, at
 *    the same time (DESIGN S1). Addresses are therefore listed, never reduced
 *    to a current one.
 *
 * 2. **Presence is measured against the last observation, not against now.**
 *    If the collector stopped running four hours ago, every device is still
 *    where it was; it is Lens that stopped looking. Reading presence off the
 *    wall clock would quietly report an empty network, which is the exact shape
 *    of lie this plugin exists to avoid.
 *
 * @package OPNsense\Lens
 */
class DeviceReport
{
    /** how long after the last observation its answer is still worth showing */
    public const OBSERVATION_STALE_AFTER = 900;

    /**
     * @param array $devices what `lens devices` returned
     * @param array $macdb OUI to vendor, as `interface list macdb` returns it
     * @param int|null $observedAt when the last observation ran, null if never
     * @param int $now
     * @return array
     */
    public static function describe(array $devices, array $macdb, ?int $observedAt, int $now): array
    {
        $rows = [];
        foreach ($devices as $device) {
            if (!is_array($device) || empty($device['mac'])) {
                continue;
            }
            $rows[] = self::device($device, $macdb, $observedAt, $now);
        }

        usort($rows, function ($left, $right) {
            if ($left['here'] !== $right['here']) {
                return $left['here'] ? -1 : 1;
            }
            return $right['last_seen'] <=> $left['last_seen'];
        });

        return [
            'devices' => $rows,
            'headline' => self::headline($rows, $observedAt, $now),
            'observed_at' => $observedAt,
            'stale' => self::stale($observedAt, $now),
            'note' => self::note($observedAt, $now),
        ];
    }

    private static function device(array $device, array $macdb, ?int $observedAt, int $now): array
    {
        $mac = (string)$device['mac'];
        $vendor = self::vendor($mac, $macdb);
        $hostname = trim((string)($device['hostname'] ?? ''));

        $addresses = self::addresses($device, $observedAt, $now);
        $here = false;
        $interfaces = [];
        foreach ($addresses as $address) {
            $here = $here || $address['current'];
            $interfaces[$address['interface']] = true;
        }

        return [
            'mac' => $mac,
            'name' => self::name($mac, $hostname, $vendor),
            'named_by' => self::namedBy($hostname, $device, $vendor),
            'vendor' => $vendor,
            'hostname' => $hostname === '' ? null : $hostname,
            'addresses' => $addresses,
            'interfaces' => array_keys($interfaces),
            'here' => $here,
            'randomised' => !empty($device['randomised']),
            'is_local' => !empty($device['is_local']),
            /* a permanent ARP entry is an address configured on this box, not a client */
            'role' => !empty($device['is_local']) ? gettext('this firewall') : null,
            'first_seen' => (int)($device['first_seen'] ?? 0),
            'last_seen' => (int)($device['last_seen'] ?? 0),
            'presence' => $here
                ? gettext('here now')
                : Duration::ago($now - (int)($device['last_seen'] ?? $now)),
            'known_for' => Duration::span($now - (int)($device['first_seen'] ?? $now)),
            'caveat' => self::caveat($device),
        ];
    }

    /**
     * An address is current when the most recent observation still saw it.
     *
     * Windows are extended with the timestamp of the run that saw them, so
     * "extended by the last run" is an exact test, not a tolerance.
     *
     * **Windows are folded here, addresses are not.** The store keeps one window
     * per absence -- a device that leaves and returns gets a second window for
     * the same address, and stage 7 needs exactly that to attribute a traffic
     * bucket to the device that held the address *at the time of the bucket*.
     * A list of windows is not a list of addresses, though: shipped unfolded,
     * every address on the operator's router appeared twice, once current and
     * once "4.8 hours ago", which is the same lie as listing one machine as two.
     */
    private static function addresses(array $device, ?int $observedAt, int $now): array
    {
        $folded = [];
        foreach ($device['addresses'] ?? [] as $window) {
            if (empty($window['address'])) {
                continue;
            }

            $address = (string)$window['address'];
            $interface = (string)($window['interface'] ?? '');
            $key = $address . '@' . $interface;
            $firstSeen = (int)($window['first_seen'] ?? 0);
            $lastSeen = (int)($window['last_seen'] ?? 0);

            if (!isset($folded[$key])) {
                $folded[$key] = [
                    'address' => $address,
                    'interface' => $interface,
                    'first_seen' => $firstSeen,
                    'last_seen' => $lastSeen,
                    'windows' => 0,
                ];
            }

            $folded[$key]['first_seen'] = min($folded[$key]['first_seen'], $firstSeen);
            $folded[$key]['last_seen'] = max($folded[$key]['last_seen'], $lastSeen);
            $folded[$key]['windows']++;
        }

        $out = [];
        foreach ($folded as $address) {
            $address['current'] = $observedAt !== null && $address['last_seen'] >= $observedAt;
            $address['seen'] = Duration::ago($now - $address['last_seen']);
            $out[] = $address;
        }

        usort($out, function ($left, $right) {
            if ($left['current'] !== $right['current']) {
                return $left['current'] ? -1 : 1;
            }
            return strcmp($left['address'], $right['address']);
        });

        return $out;
    }

    /**
     * The best name available today. Stage 6 puts the operator's own label in
     * front of all of these; until then nothing here is invented -- a device
     * with no hostname and no known vendor is shown as its MAC, not as a guess.
     */
    private static function name(string $mac, string $hostname, ?string $vendor): string
    {
        if ($hostname !== '') {
            return $hostname;
        }

        $tail = strtoupper(substr(str_replace(':', '', $mac), -6));
        if ($vendor !== null) {
            return sprintf('%s %s', $vendor, $tail);
        }

        return $mac;
    }

    private static function namedBy(string $hostname, array $device, ?string $vendor): string
    {
        if ($hostname !== '') {
            $source = (string)($device['hostname_source'] ?? '');
            return $source === ''
                ? gettext('hostname')
                : sprintf(gettext('hostname, from %s'), $source);
        }

        if ($vendor !== null) {
            return gettext('hardware vendor only - it announces no name');
        }

        return gettext('nothing announced, and the vendor is not in the database');
    }

    /**
     * @return string|null what to warn about this device, if anything
     */
    private static function caveat(array $device): ?string
    {
        if (!empty($device['randomised'])) {
            return gettext(
                'Randomised MAC address. This device may reappear as a different '
                . 'entry after it rejoins the network.'
            );
        }

        return null;
    }

    private static function vendor(string $mac, array $macdb): ?string
    {
        $oui = strtoupper(substr(str_replace(':', '', $mac), 0, 6));

        return isset($macdb[$oui]) && $macdb[$oui] !== '' ? (string)$macdb[$oui] : null;
    }

    private static function stale(?int $observedAt, int $now): bool
    {
        return $observedAt === null || ($now - $observedAt) > self::OBSERVATION_STALE_AFTER;
    }

    private static function headline(array $rows, ?int $observedAt, int $now): string
    {
        if ($rows === []) {
            return $observedAt === null
                ? gettext('No devices yet. The collector has not run.')
                : gettext('The last observation found no devices on any interface.');
        }

        $here = count(array_filter($rows, function ($row) {
            return $row['here'];
        }));

        if (self::stale($observedAt, $now)) {
            return sprintf(gettext('%d devices known.'), count($rows));
        }

        return sprintf(gettext('%d devices known, %d here now.'), count($rows), $here);
    }

    /**
     * Said out loud rather than shown as an absence: the difference between
     * "nothing is on the network" and "nobody looked" is the whole point.
     */
    private static function note(?int $observedAt, int $now): ?string
    {
        if ($observedAt === null) {
            return gettext(
                'Nothing has been observed yet. Run "configctl lens observe" once, '
                . 'or wait for the five-minute job to run.'
            );
        }

        if (!self::stale($observedAt, $now)) {
            return null;
        }

        return sprintf(
            gettext(
                'The last observation was %s. Nothing below is known to be '
                . 'present right now - it is what was true then. The observation '
                . 'job runs every five minutes; if this stays stale, it is not running.'
            ),
            Duration::ago($now - $observedAt)
        );
    }
}
