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
 * Who a device talks to, in words (§4.62). Pure: `lens destinations` in, rows
 * for the device page out.
 *
 * The port is core's guess at the service -- the lower of the two ports of a
 * flow -- and the name beside it is a guess about that guess, so it is only
 * given for the well-known ones and otherwise left as the number.
 *
 * @package OPNsense\Lens
 */
class Destinations
{
    /** rows the card shows; the rest are still in the total */
    public const SHOWN = 15;

    /** protocol numbers as core records them */
    private const PROTOCOLS = [1 => 'ICMP', 6 => 'TCP', 17 => 'UDP', 58 => 'ICMPv6'];

    /** the services a household or a small office actually sees */
    private const SERVICES = [
        22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 67 => 'DHCP', 80 => 'HTTP', 110 => 'POP3',
        123 => 'NTP', 143 => 'IMAP', 443 => 'HTTPS', 445 => 'SMB', 465 => 'SMTPS', 587 => 'mail submission',
        853 => 'DNS over TLS', 993 => 'IMAPS', 995 => 'POP3S', 1194 => 'OpenVPN', 1883 => 'MQTT',
        1900 => 'SSDP', 3074 => 'Xbox Live', 3389 => 'RDP', 3478 => 'STUN', 5222 => 'XMPP',
        5223 => 'Apple push', 5228 => 'Google push', 5353 => 'mDNS', 8080 => 'HTTP (alt)',
        8443 => 'HTTPS (alt)', 8883 => 'MQTT over TLS', 51820 => 'WireGuard',
    ];

    /**
     * @param array $raw `lens destinations` decoded
     * @return array enabled, rows, other, totals, and what the numbers cover
     */
    public static function describe(array $raw): array
    {
        $enabled = !empty($raw['enabled']);
        $rows = [];
        $other = ['sent' => 0, 'received' => 0];
        $total = 0;

        foreach ((array)($raw['rows'] ?? []) as $row) {
            $sent = (int)($row['sent'] ?? 0);
            $received = (int)($row['received'] ?? 0);
            $total += $sent + $received;
            if (($row['peer'] ?? '*') === '*') {
                $other['sent'] += $sent;
                $other['received'] += $received;
                continue;
            }
            $rows[] = [
                'peer' => (string)$row['peer'],
                'port' => (int)($row['port'] ?? 0),
                'service' => self::service((int)($row['port'] ?? 0), (int)($row['protocol'] ?? 0)),
                'sent' => $sent,
                'received' => $received,
                'octets' => $sent + $received,
                'traffic' => Bytes::human($sent + $received),
                'sent_text' => Bytes::human($sent),
                'received_text' => Bytes::human($received),
                'days' => (int)($row['days'] ?? 0),
            ];
        }

        usort($rows, function ($left, $right) {
            return $right['octets'] <=> $left['octets'];
        });
        foreach (array_slice($rows, self::SHOWN) as $row) {
            $other['sent'] += $row['sent'];
            $other['received'] += $row['received'];
        }
        $rows = array_slice($rows, 0, self::SHOWN);
        $largest = $rows[0]['octets'] ?? 0;
        foreach ($rows as &$row) {
            $row['share'] = $total > 0 ? (int)round($row['octets'] / $total * 100) : 0;
            $row['share_text'] = $row['share'] === 0 && $row['octets'] > 0 ? 'under 1%' : $row['share'] . '%';
            $row['bar'] = $largest > 0 ? round($row['octets'] / $largest * 100, 1) : 0;
        }
        unset($row);

        $days = (int)($raw['days'] ?? 30);

        return [
            'enabled' => $enabled,
            'days' => $days,
            'rows' => $rows,
            'other' => $other['sent'] + $other['received'] > 0 ? [
                'traffic' => Bytes::human($other['sent'] + $other['received']),
                'sent_text' => Bytes::human($other['sent']),
                'received_text' => Bytes::human($other['received']),
            ] : null,
            'total' => Bytes::human($total),
            'note' => self::note($enabled, $rows === [] && $other['sent'] + $other['received'] === 0, $days),
        ];
    }

    private static function service(int $port, int $protocol): string
    {
        $name = self::PROTOCOLS[$protocol] ?? ($protocol > 0 ? 'protocol ' . $protocol : '');
        if ($protocol === 1 || $protocol === 58) {
            return $name;
        }

        return trim(($port > 0 ? $port . ' ' : '') . $name
            . (isset(self::SERVICES[$port]) ? ' · ' . self::SERVICES[$port] : ''));
    }

    private static function note(bool $enabled, bool $empty, int $days): string
    {
        if (!$enabled) {
            return gettext(
                'Lens keeps who each device talks to only when it is switched on, under Services: Lens: '
                . 'Settings. It is off until then because it describes what a person does.'
            );
        }
        if ($empty) {
            return gettext(
                'Nothing yet. Destinations are copied once a day, for days that have ended, so the '
                . 'first ones appear the day after switching this on.'
            );
        }

        return sprintf(
            gettext('The last %d days, per day as OPNsense records them: which day, not which hour.'),
            $days
        );
    }
}
