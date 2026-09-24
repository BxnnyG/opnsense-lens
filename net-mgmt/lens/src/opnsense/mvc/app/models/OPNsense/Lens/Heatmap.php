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
 * Class Heatmap
 *
 * Bytes per hour of the week as a 7 x 24 grid. Pure.
 *
 * Monday first, because the operator's week starts on Monday and SQLite's
 * starts on Sunday. Five shades, scaled to the busiest cell, so the pattern is
 * visible whether the device moves kilobytes or terabytes.
 *
 * @package OPNsense\Lens
 */
class Heatmap
{
    public const LEVELS = 4;

    /**
     * @param array $cells [dow (0 = Sunday), hour, octets]
     * @return array
     */
    public static function grid(array $cells): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($cells as $cell) {
            if (!is_array($cell) || count($cell) < 3) {
                continue;
            }
            $day = ((int)$cell[0] + 6) % 7;
            $hour = (int)$cell[1];
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $grid[$day][$hour] += (int)$cell[2];
        }

        $peak = 0;
        foreach ($grid as $row) {
            $peak = max($peak, max($row));
        }

        $rows = [];
        foreach ($grid as $day => $row) {
            $out = [];
            foreach ($row as $hour => $octets) {
                $out[] = [
                    'octets' => $octets,
                    'level' => self::level($octets, $peak),
                    'text' => Bytes::human($octets),
                ];
            }
            $rows[] = $out;
        }

        return [
            'rows' => $rows,
            'days' => [gettext('Mon'), gettext('Tue'), gettext('Wed'), gettext('Thu'),
                       gettext('Fri'), gettext('Sat'), gettext('Sun')],
            'peak' => Bytes::human($peak),
            'busiest' => self::busiest($grid),
            'empty' => $peak === 0,
        ];
    }

    /**
     * Square root before bucketing: traffic is heavy-tailed, and a linear scale
     * paints one backup hour dark and every other hour of the week blank.
     */
    private static function level(int $octets, int $peak): int
    {
        if ($octets <= 0 || $peak <= 0) {
            return 0;
        }

        return max(1, (int)ceil(sqrt($octets / $peak) * self::LEVELS));
    }

    private static function busiest(array $grid): ?array
    {
        $best = null;
        foreach ($grid as $day => $row) {
            foreach ($row as $hour => $octets) {
                if ($octets > 0 && ($best === null || $octets > $best[2])) {
                    $best = [$day, $hour, $octets];
                }
            }
        }

        return $best === null ? null : ['day' => $best[0], 'hour' => $best[1]];
    }
}
