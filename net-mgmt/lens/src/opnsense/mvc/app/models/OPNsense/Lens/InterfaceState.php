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
 * Class InterfaceState
 *
 * One interface's link, as core's Interfaces: Overview reads it (stage 51,
 * §4.84): up or not, at what speed, with which addresses, and how many of its
 * packets went wrong since boot. Pure: the controller hands in core's
 * `interface list ifconfig` and `interface list stats` as they came.
 *
 * @package OPNsense\Lens
 */
class InterfaceState
{
    /** errors as a share of packets from which an interface is worth a look */
    public const ERROR_SHARE = 0.001;
    /** and an absolute floor, so ten errors in ten thousand packets after boot are not news */
    public const ERROR_FLOOR = 100;

    /**
     * @param array $details one device's entry of `interface list ifconfig`
     * @param array $stats one device's entry of `interface list stats`
     * @return array ['status', 'tone', 'media', 'addresses', 'vlan', 'errors', 'error_text', 'mac']
     */
    public static function describe(array $details, array $stats): array
    {
        /* core's rule (OverviewController::parseIfInfo): up by the flags, and
           ifconfig's own status where it says more than "active" */
        $status = in_array('up', (array)($details['flags'] ?? []), true) ? 'up' : 'down';
        $raw = (string)($details['status'] ?? '');
        if ($raw !== '' && !in_array($raw, ['active', 'running'], true)) {
            $status = $raw;
        }

        $addresses = [];
        foreach (['ipv4', 'ipv6'] as $family) {
            foreach ((array)($details[$family] ?? []) as $entry) {
                $address = (string)($entry['ipaddr'] ?? '');
                if ($address === '' || stripos($address, 'fe80:') === 0) {
                    continue;
                }
                $addresses[] = $address . (isset($entry['subnetbits']) ? '/' . (int)$entry['subnetbits'] : '');
            }
        }

        $errors = (int)($stats['input errors'] ?? 0) + (int)($stats['output errors'] ?? 0)
            + (int)($stats['collisions'] ?? 0);
        $packets = (int)($stats['packets received'] ?? 0) + (int)($stats['packets transmitted'] ?? 0);
        $share = $packets > 0 ? $errors / $packets : 0.0;
        $erring = $errors >= self::ERROR_FLOOR && $share >= self::ERROR_SHARE;

        $wired = !empty($details['is_physical']) || isset($details['vlan']['tag']);
        $errorText = gettext('no errors since boot');
        if ($errors > 0) {
            $shareText = $share < 0.00001
                ? gettext('under 0.001%')
                : rtrim(rtrim(number_format($share * 100, 3), '0'), '.') . '%';
            $errorText = sprintf(
                $errors === 1 ? gettext('%s error since boot (%s of packets)')
                              : gettext('%s errors since boot (%s of packets)'),
                number_format($errors),
                $shareText
            );
        }

        return [
            'status' => $status,
            'tone' => $status === 'up' ? ($erring ? 'warn' : 'good') : 'bad',
            /* no speed on a link that is not up; and a line rate only where there is a
               wire behind it -- PPPoE, WireGuard and lo0 report one that means nothing
               (router-01's pppoe0 read "0.1 Mbit/s", box-2's dark GPON "10 Gbit/s") */
            'media' => $status !== 'up' ? null : self::media(
                (string)($details['media'] ?? ''),
                $wired ? (string)($stats['line rate'] ?? '') : ''
            ),
            'addresses' => $addresses,
            'vlan' => isset($details['vlan']['tag']) ? (int)$details['vlan']['tag'] : null,
            'errors' => $errors,
            'error_text' => $errorText,
            'mac' => isset($details['macaddr']) ? (string)$details['macaddr'] : null,
        ];
    }

    /**
     * The health row's tile over the interfaces the operator assigned and
     * enabled (§4.84): all up, or the ones that are not.
     *
     * @param array $links name => describe() of each assigned, enabled interface
     */
    public static function tile(array $links): ?array
    {
        if ($links === []) {
            return null;
        }
        $down = [];
        $erring = [];
        foreach ($links as $name => $link) {
            if ($link['status'] !== 'up') {
                $down[] = sprintf('%s: %s', $name, $link['status']);
            } elseif ($link['tone'] === 'warn') {
                $erring[] = $name;
            }
        }

        $tile = ['key' => 'interfaces', 'title' => gettext('Interfaces'), 'icon' => 'fa-sitemap',
                 'link' => '/ui/lens/segments', 'detail' => [sprintf(gettext('%d assigned'), count($links))]];
        if ($down !== []) {
            return array_merge($tile, ['tone' => 'bad', 'sentence' => count($down) === 1
                ? sprintf(gettext('%s.'), $down[0])
                : sprintf(gettext('%d interfaces are not up: %s.'), count($down), implode(', ', $down))]);
        }
        if ($erring !== []) {
            return array_merge($tile, ['tone' => 'warn', 'sentence' => sprintf(
                gettext('Up, but %s keeps losing packets to errors.'),
                implode(', ', $erring)
            )]);
        }

        return array_merge($tile, ['tone' => 'good', 'sentence' => count($links) === 1
            ? gettext('The one interface is up.')
            : sprintf(gettext('All %d interfaces are up.'), count($links))]);
    }

    /** "1000baseT <full-duplex>" as "1 Gbit/s, full duplex"; else the line rate; else nothing */
    private static function media(string $media, string $lineRate): ?string
    {
        if (preg_match('/(\d+)(G?)base[^\s<]*(?:\s*<([^>]*)>)?/i', $media, $match)) {
            $options = strtolower($match[3] ?? '');
            $duplex = '';
            if (strpos($options, 'full-duplex') !== false) {
                $duplex = ', ' . gettext('full duplex');
            } elseif (strpos($options, 'half-duplex') !== false) {
                $duplex = ', ' . gettext('half duplex');
            }
            return self::speed((float)$match[1] * (strtoupper($match[2]) === 'G' ? 1000 : 1)) . $duplex;
        }
        if (preg_match('/^(\d+)\s*bit/i', $lineRate, $match) && (int)$match[1] > 0) {
            return self::speed((int)$match[1] / 1e6);
        }

        return null;
    }

    private static function speed(float $mbit): string
    {
        $trim = function (float $value): string {
            return rtrim(rtrim(number_format($value, 1), '0'), '.');
        };

        return $mbit >= 1000 ? $trim($mbit / 1000) . ' Gbit/s' : $trim($mbit) . ' Mbit/s';
    }
}
