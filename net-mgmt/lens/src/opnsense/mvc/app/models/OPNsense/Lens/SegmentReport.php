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
 * Class SegmentReport
 *
 * How much traffic each network carried, and how much of it Lens can put a
 * device to. Pure.
 *
 * The second column is the whole point (§4.41). Core's Insight already totals
 * bytes per interface; a segment that is 95% unnamed is not a busy segment, it
 * is one whose traffic belongs to machines that are not attached to it, and a
 * plain total cannot tell the two apart.
 *
 * @package OPNsense\Lens
 */
class SegmentReport
{
    /** a segment Lens can name less of than this is worth saying out loud */
    public const THIN = 0.5;

    /**
     * @param array $raw what `lens segments` returned
     * @param array $names device name (`lagg0_vlan24`) to the operator's name
     * @return array
     */
    public static function describe(array $raw, array $names): array
    {
        $deviceInterfaces = array_flip((array)($raw['device_interfaces'] ?? []));

        $rows = [];
        $total = 0;

        foreach ($raw['segments'] ?? [] as $segment) {
            $interface = (string)($segment['interface'] ?? '');
            if ($interface === '') {
                continue;
            }

            $octets = (int)($segment['octets'] ?? 0);
            $named = (int)($segment['named'] ?? 0);
            $total += $octets;

            $rows[] = [
                'interface' => $interface,
                'name' => $names[$interface] ?? self::guessName($interface),
                'is_network' => isset($deviceInterfaces[$interface]),
                'octets' => $octets,
                'traffic' => Bytes::human($octets),
                'sent' => Bytes::human((int)($segment['sent'] ?? 0)),
                'received' => Bytes::human((int)($segment['received'] ?? 0)),
                'named' => $named,
                'named_text' => Bytes::human($named),
                'named_share' => $octets > 0 ? $named / $octets : 0.0,
                'addresses' => (int)($segment['addresses'] ?? 0),
                'note' => self::note($interface, $octets, $named, isset($deviceInterfaces[$interface])),
            ];
        }

        return [
            'segments' => $rows,
            'total' => Bytes::human($total),
            'total_octets' => $total,
            'hours' => (int)($raw['hours'] ?? 0),
        ];
    }

    /**
     * Why a segment's second column looks the way it does.
     *
     * Said per row rather than once at the bottom, because the answer is
     * different for each and a single footnote would have to be vague enough to
     * cover all of them.
     */
    private static function note(string $interface, int $octets, int $named, bool $isNetwork): ?string
    {
        if ($interface === '0') {
            return gettext(
                'flowd could not place these flows on any interface at all. They are '
                . 'counted so the totals add up, and they belong to nothing else here.'
            );
        }

        if ($interface === 'lo0') {
            return gettext('the firewall talking to itself.');
        }

        if (!$isNetwork) {
            return gettext(
                'no device was ever observed here, so this is the far side of the '
                . 'line - the addresses on it are the internet\'s, not yours.'
            );
        }

        if ($octets > 0 && $named / $octets < self::THIN) {
            return gettext(
                'most of this has no device on this segment. Usually a network routed '
                . 'through the firewall rather than attached to it.'
            );
        }

        return null;
    }

    /**
     * A device name with no interface configured under it still has to read as
     * something. `lagg0_vlan24` is worse than `VLAN 24` and better than blank.
     */
    private static function guessName(string $interface): string
    {
        if ($interface === '0') {
            return gettext('(unplaced)');
        }

        if (preg_match('/_vlan(\d+)$/', $interface, $found)) {
            return sprintf(gettext('VLAN %d'), (int)$found[1]);
        }

        return $interface;
    }
}
