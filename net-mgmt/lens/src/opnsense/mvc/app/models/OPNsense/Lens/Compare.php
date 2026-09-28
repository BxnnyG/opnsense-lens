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
 * Class Compare
 *
 * Up to four devices side by side (§4.73): each one's history as its own page
 * reads it, aligned on one time axis, and the difference in one sentence.
 * The slot is the device's place in the request, so its colour never moves
 * when another device is added or the range changes.
 *
 * @package OPNsense\Lens
 */
class Compare
{
    public const MAX = 4;

    /**
     * @param array $entries per device, in the order asked: ['row' => a
     *                       DeviceReport row or null, 'macs' => its MACs,
     *                       'raw' => `lens device` decoded]
     * @param int $hours the window asked for
     * @return array
     */
    public static function describe(array $entries, int $hours): array
    {
        $step = 3600;
        $buckets = [];
        $devices = [];
        $all = 0;

        foreach (array_values($entries) as $slot => $entry) {
            $raw = (array)($entry['raw'] ?? []);
            $row = $entry['row'] ?? null;
            $step = (int)($raw['step'] ?? $step);

            $values = [];
            $sent = 0;
            $received = 0;
            $busiest = null;
            foreach ((array)($raw['series'] ?? []) as $point) {
                $at = (int)($point['bucket'] ?? 0);
                $total = (int)($point['sent'] ?? 0) + (int)($point['received'] ?? 0);
                $sent += (int)($point['sent'] ?? 0);
                $received += (int)($point['received'] ?? 0);
                $values[$at] = $total;
                $buckets[$at] = true;
                if ($total > 0 && ($busiest === null || $total > $busiest['octets'])) {
                    $busiest = ['bucket' => $at, 'octets' => $total];
                }
            }
            $all += $sent + $received;

            $devices[] = [
                'slot' => $slot + 1,
                'mac' => (string)($row['mac'] ?? ($entry['macs'][0] ?? '')),
                'macs' => array_values((array)($entry['macs'] ?? [])),
                'name' => (string)($row['name'] ?? ($entry['macs'][0] ?? '')),
                'icon' => (string)($row['kind']['icon'] ?? 'fa-circle-o'),
                'owner' => $row['owner'] ?? null,
                'here' => (bool)($row['here'] ?? false),
                'known' => $row !== null,
                'octets' => $sent + $received,
                'total' => Bytes::human($sent + $received),
                'sent' => Bytes::human($sent),
                'received' => Bytes::human($received),
                'sent_octets' => $sent,
                'received_octets' => $received,
                'busiest' => $busiest === null ? null : [
                    'bucket' => $busiest['bucket'],
                    'what' => Bytes::human($busiest['octets']),
                ],
                'values' => $values,
            ];
        }

        ksort($buckets);
        $axis = array_keys($buckets);
        $peak = 0;
        foreach ($devices as $index => $device) {
            $line = [];
            foreach ($axis as $at) {
                $line[] = $device['values'][$at] ?? 0;
            }
            $peak = max($peak, $line === [] ? 0 : max($line));
            $devices[$index]['series'] = $line;
            $devices[$index]['share'] = $all > 0 ? (int)round($device['octets'] / $all * 100) : 0;
            unset($devices[$index]['values']);
        }

        return [
            'hours' => $hours,
            'step' => $step,
            'buckets' => $axis,
            'peak' => $peak,
            'peak_text' => Bytes::human($peak),
            'devices' => $devices,
            'sentence' => self::sentence($devices, $hours),
        ];
    }

    private static function sentence(array $devices, int $hours): string
    {
        $range = $hours <= 24
            ? gettext('In the last 24 hours')
            : sprintf(gettext('In the last %d days'), intdiv($hours, 24));
        if (count($devices) < 2) {
            return gettext('Choose a second device to compare with.');
        }

        $ranked = $devices;
        usort($ranked, function ($left, $right) {
            return $right['octets'] <=> $left['octets'];
        });
        [$first, $second] = $ranked;

        if ($first['octets'] === 0) {
            return sprintf(gettext('%s, none of them moved anything.'), $range);
        }
        if ($second['octets'] === 0) {
            return sprintf(gettext('%s, only %s moved anything: %s.'), $range, $first['name'], $first['total']);
        }

        $ratio = $first['octets'] / $second['octets'];
        if ($ratio < 1.1) {
            return sprintf(
                gettext('%s, %s and %s moved about the same: %s and %s.'),
                $range,
                $first['name'],
                $second['name'],
                $first['total'],
                $second['total']
            );
        }

        return sprintf(
            gettext('%s, %s moved %s, %s times as much as %s (%s).'),
            $range,
            $first['name'],
            $first['total'],
            $ratio < 10 ? number_format($ratio, 1) : (string)(int)round($ratio),
            $second['name'],
            $second['total']
        );
    }
}
