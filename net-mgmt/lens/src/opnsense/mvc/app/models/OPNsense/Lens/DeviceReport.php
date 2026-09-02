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
    public static function describe(
        array $devices,
        array $macdb,
        array $traffic,
        ?int $observedAt,
        int $now
    ): array {
        $totals = is_array($traffic['devices'] ?? null) ? $traffic['devices'] : [];

        $rows = [];
        $measured = 0;
        foreach ($devices as $device) {
            if (!is_array($device) || empty($device['mac'])) {
                continue;
            }
            $row = self::device($device, $macdb, $observedAt, $now);
            $row = array_merge($row, self::traffic($totals[$row['mac']] ?? []));
            $measured += $row['octets'];
            $rows[] = $row;
        }

        usort($rows, function ($left, $right) use ($measured) {
            /* the question this page exists to answer is "who used 4 GB", so
               traffic leads -- until there is none, when presence does */
            if ($measured > 0 && $left['octets'] !== $right['octets']) {
                return $right['octets'] <=> $left['octets'];
            }
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
            'accounting' => self::accounting($traffic, $measured),
            'kinds' => DeviceType::choices(),
            'groups' => self::groups($rows),
            'summary' => self::summary($rows, $measured, $now),
        ];
    }

    /**
     * The aggregator names its directions for the interface, not for the device:
     * a flow the device sent *entered* the interface. So `in` is what the device
     * sent and `out` is what it received (DESIGN 1.4, the double write).
     */
    private static function traffic(array $totals): array
    {
        $sent = (int)($totals['in']['octets'] ?? 0);
        $received = (int)($totals['out']['octets'] ?? 0);

        return [
            'octets' => $sent + $received,
            'sent' => $sent,
            'received' => $received,
            'traffic' => $sent + $received > 0
                ? sprintf(
                    gettext('%s  (%s up, %s down)'),
                    Bytes::human($sent + $received),
                    Bytes::human($sent),
                    Bytes::human($received)
                )
                : null,
        ];
    }

    /**
     * What the totals above do *not* cover, said out loud.
     *
     * Every harvested byte lands in exactly one of these lines or in a device.
     * A top-talkers table that quietly drops what it cannot explain is the kind
     * of number people make decisions on, so the remainder sits next to it.
     */
    private static function accounting(array $traffic, int $measured): array
    {
        $reasons = [
            'far_end' => gettext(
                'The far end of a flow - the internet address it was talking to. '
                . 'NetFlow records every flow twice, once for each end.'
            ),
            'not_watching' => gettext(
                'Measured before Lens started watching. The first harvest reaches '
                . '23 hours back; identity only starts when the collector does. '
                . 'Nothing to fix - this shrinks to nothing on its own.'
            ),
            'unknown' => gettext(
                'On one of your own segments, Lens was watching, and still nothing '
                . 'was seen holding that address that hour. Either the collector '
                . 'stopped, the device never answered ARP, or the address belongs '
                . 'to a network routed through this firewall rather than to a '
                . 'device attached to it. The list below says which.'
            ),
            'ambiguous' => gettext(
                'Two devices held the same address inside one hour. The hour cannot '
                . 'be split between them, and guessing would be worse than saying so.'
            ),
        ];

        $rows = [];
        foreach ($reasons as $key => $why) {
            $octets = (int)($traffic['unattributed'][$key]['octets'] ?? 0);
            if ($octets > 0) {
                $rows[] = ['what' => Bytes::human($octets), 'why' => $why, 'reason' => $key];
            }
        }

        $names = [
            'far_end' => gettext('far end'),
            'not_watching' => gettext('before Lens watched'),
            'unknown' => gettext('nobody held it'),
            'ambiguous' => gettext('two devices'),
        ];

        $unexplained = [];
        foreach ($traffic['unexplained'] ?? [] as $entry) {
            if (empty($entry['address'])) {
                continue;
            }
            $unexplained[] = [
                'address' => (string)$entry['address'],
                'interface' => (string)($entry['interface'] ?? ''),
                'reason' => $names[$entry['reason'] ?? ''] ?? (string)($entry['reason'] ?? ''),
                'what' => Bytes::human((int)($entry['octets'] ?? 0)),
                'hours' => (int)($entry['hours'] ?? 0),
            ];
        }

        return [
            'attributed' => Bytes::human($measured),
            'attributed_octets' => $measured,
            'hours' => (int)($traffic['hours'] ?? 0),
            'rows' => $rows,
            'unexplained' => $unexplained,
        ];
    }

    private static function device(array $device, array $macdb, ?int $observedAt, int $now): array
    {
        $mac = (string)$device['mac'];
        $vendor = self::vendor($mac, $macdb);
        $hostname = trim((string)($device['hostname'] ?? ''));

        /* what the operator said, kept apart from what the box observed: it
           wins where it exists and is never written over by an observation */
        $label = is_array($device['label'] ?? null) ? $device['label'] : [];
        $chosenName = trim((string)($label['name'] ?? ''));
        $chosenKind = trim((string)($label['kind'] ?? ''));
        $tags = self::tags($label['tags'] ?? null);

        $addresses = self::addresses($device, $observedAt, $now);
        $here = false;
        $interfaces = [];
        foreach ($addresses as $address) {
            $here = $here || $address['current'];
            $interfaces[$address['interface']] = true;
        }

        return [
            'mac' => $mac,
            'name' => $chosenName !== '' ? $chosenName : self::name($mac, $hostname, $vendor),
            'named_by' => $chosenName !== ''
                ? gettext('you named it')
                : self::namedBy($hostname, $device, $vendor),
            'label' => [
                'name' => $chosenName,
                'kind' => $chosenKind,
                'tags' => implode(', ', $tags),
                'note' => trim((string)($label['note'] ?? '')),
            ],
            'tags' => $tags,
            'vendor' => $vendor,
            'hostname' => $hostname === '' ? null : $hostname,
            'addresses' => $addresses,
            'interfaces' => array_keys($interfaces),
            'here' => $here,
            'randomised' => !empty($device['randomised']),
            'is_local' => !empty($device['is_local']),
            'kind' => ($chosenKind !== '' ? DeviceType::chosen($chosenKind) : null)
                ?? DeviceType::of($vendor, $hostname, !empty($device['is_local'])),
            'group' => self::groupKey($tags, $vendor, !empty($device['is_local'])),
            /* everything a search box should match, assembled once here rather
               than reassembled in the browser on every keystroke */
            'haystack' => strtolower(implode(' ', array_merge(
                [$mac, $hostname, (string)$vendor, $chosenName, implode(' ', $tags)],
                array_map(function ($address) {
                    return $address['address'] . ' ' . $address['interface'];
                }, $addresses)
            ))),
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

    /** a day's absence is the shortest gap worth calling one */
    public const AWAY_AFTER = 86400;

    /**
     * How long Lens must have been watching before "new" means anything.
     *
     * On a box installed an hour ago every device is new, which is true and
     * useless. Two days is the first point at which "appeared yesterday" is a
     * statement about the network rather than about the install date.
     */
    public const NEW_NEEDS = 2 * 86400;

    /**
     * The four sentences worth reading before the table.
     *
     * Every figure here comes from the rows below it, so the strip and the list
     * cannot disagree -- the same reason §4.32 gave for sharing one query.
     */
    private static function summary(array $rows, int $measured, int $now): array
    {
        if ($rows === []) {
            return ['known' => 0, 'watching_for' => null, 'new' => [], 'away' => 0];
        }

        $earliest = $now;
        $new = [];
        $away = 0;

        foreach ($rows as $row) {
            $earliest = min($earliest, $row['first_seen'] ?: $now);

            if ($row['first_seen'] > 0 && $now - $row['first_seen'] < self::AWAY_AFTER) {
                $new[] = $row['name'];
            }
            if ($row['last_seen'] > 0 && $now - $row['last_seen'] >= self::AWAY_AFTER) {
                $away++;
            }
        }

        $watchingFor = $now - $earliest;
        $heaviest = $rows[0]['octets'] > 0 ? $rows[0] : null;

        return [
            'known' => count($rows),
            'here' => count(array_filter($rows, function ($row) {
                return $row['here'];
            })),
            'watching_for' => Duration::span($watchingFor),
            /* below the threshold the answer is "everything", which says
               something about the install and nothing about the network */
            'new' => $watchingFor >= self::NEW_NEEDS ? $new : [],
            'new_yet' => $watchingFor >= self::NEW_NEEDS,
            'away' => $away,
            'moved' => Bytes::human($measured),
            'busiest' => $heaviest === null ? null : [
                'name' => $heaviest['name'],
                'what' => Bytes::human($heaviest['octets']),
            ],
        ];
    }

    /** below this, collapsing a set costs a click and saves nothing */
    public const GROUP_FROM = 3;

    /**
     * What this device would be filed under, if anything.
     *
     * The operator's first tag wins, because a tag is a statement of intent and
     * a vendor string is an accident of procurement. Failing that, the hardware
     * vendor — box 2 lists thirty rows reading `Proxmox Server Solutions GmbH`,
     * which is one hypervisor's worth of virtual NICs and reads as thirty
     * machines. The firewall is never grouped: it is one machine and it is
     * already labelled as itself.
     */
    private static function groupKey(array $tags, ?string $vendor, bool $isLocal): ?string
    {
        if ($isLocal) {
            return null;
        }

        if ($tags !== []) {
            return 'tag:' . $tags[0];
        }

        return $vendor !== null && $vendor !== '' ? 'vendor:' . $vendor : null;
    }

    /**
     * The groups worth offering, with what each is worth.
     *
     * A group of one or two is not a group. Offering it would trade a row the
     * reader can see for a row they have to open, which is the opposite of what
     * this is for.
     */
    private static function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row['group'] === null) {
                continue;
            }

            if (!isset($groups[$row['group']])) {
                list($kind, $label) = explode(':', $row['group'], 2);
                $groups[$row['group']] = [
                    'key' => $row['group'],
                    'label' => $label,
                    'by' => $kind === 'tag' ? gettext('your tag') : gettext('hardware vendor'),
                    'icon' => $row['kind']['icon'],
                    'count' => 0,
                    'here' => 0,
                    'octets' => 0,
                ];
            }

            $groups[$row['group']]['count']++;
            $groups[$row['group']]['here'] += $row['here'] ? 1 : 0;
            $groups[$row['group']]['octets'] += $row['octets'];
        }

        $offered = [];
        foreach ($groups as $group) {
            if ($group['count'] < self::GROUP_FROM) {
                continue;
            }
            $group['traffic'] = $group['octets'] > 0 ? Bytes::human($group['octets']) : null;
            $offered[] = $group;
        }

        usort($offered, function ($left, $right) {
            return $right['octets'] <=> $left['octets'];
        });

        return $offered;
    }

    /**
     * Tags are one text field the operator types, because a tag editor is a
     * whole interaction and a comma is one they already know.
     *
     * @return array unique, trimmed, in the order given
     */
    private static function tags($raw): array
    {
        $tags = [];
        foreach (explode(',', (string)$raw) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
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
