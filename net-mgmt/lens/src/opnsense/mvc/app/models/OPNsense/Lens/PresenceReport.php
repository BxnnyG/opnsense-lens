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
 * Class PresenceReport
 *
 * Who was home, and when. Pure.
 *
 * Names, icons and whether a device is here now all come from DeviceReport's
 * rows, so this page cannot call a device something the list does not (§4.37).
 * What this adds is the spans, and one decision about order (§4.52):
 *
 * **Devices that were here the whole time are folded away.** On the operator's
 * second firewall that is most of the list -- servers, access points, thirty
 * virtual machines -- and a chart of forty solid bars says nothing. The rows
 * that come and go are the ones that answer "who's home", so they lead. The
 * split is measured, not guessed: coverage of the window, not a device type.
 *
 * @package OPNsense\Lens
 */
class PresenceReport
{
    /** present for at least this share of the window counts as "always here" */
    public const ALWAYS = 0.98;

    /**
     * @param array $rows DeviceReport's device rows
     * @param array $raw what `lens presence` returned
     * @param int $now
     * @return array
     */
    public static function describe(array $rows, array $raw, int $now): array
    {
        $start = (int)($raw['start'] ?? $now);
        $since = (int)($raw['since'] ?? $start);
        $length = max(1, $now - $start);
        $found = (array)($raw['devices'] ?? []);

        $moving = [];
        $always = [];
        $absent = 0;

        foreach ($rows as $row) {
            $spans = (array)($found[$row['mac']]['spans'] ?? []);
            $seconds = (int)($found[$row['mac']]['seconds'] ?? 0);

            if ($spans === []) {
                $absent++;
                continue;
            }

            $coverage = min(1.0, $seconds / $length);
            $entry = [
                'mac' => $row['mac'],
                'name' => $row['name'],
                'icon' => $row['kind']['icon'] ?? 'fa-circle-o',
                'here' => (bool)$row['here'],
                'spans' => array_map(function ($span) {
                    return [(int)$span[0], (int)$span[1]];
                }, $spans),
                'present' => Duration::span($seconds),
                'coverage' => (int)round($coverage * 100),
                'last' => (int)end($spans)[1],
            ];

            if ($coverage >= self::ALWAYS) {
                $always[] = $entry;
            } else {
                $moving[] = $entry;
            }
        }

        usort($moving, function ($left, $right) {
            if ($left['here'] !== $right['here']) {
                return $left['here'] ? -1 : 1;
            }
            return $right['last'] <=> $left['last'];
        });

        usort($always, function ($left, $right) {
            return strcmp($left['name'], $right['name']);
        });

        return [
            'start' => $start,
            'now' => $now,
            'moving' => $moving,
            'always' => $always,
            'absent' => $absent,
            'note' => $start > $since + 3600
                ? sprintf(
                    gettext('Lens has been watching for %s, so the chart begins there.'),
                    Duration::span($now - $start)
                )
                : null,
        ];
    }
}
