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
 * Class DeviceDetail
 *
 * One device's hourly history, shaped for drawing. Pure.
 *
 * The series is deliberately *not* trimmed to the hours that have traffic. A
 * chart drawn only over busy hours makes a device that was quiet all night look
 * like one that was busy all night, which is the opposite of what the reader
 * asked. Empty hours are hours, and they are drawn as such.
 *
 * @package OPNsense\Lens
 */
class DeviceDetail
{
    /**
     * @param array $raw what `lens device` returned
     * @param int $now
     * @return array
     */
    public static function describe(array $raw, int $now): array
    {
        $series = [];
        $peak = 0;

        foreach ($raw['series'] ?? [] as $point) {
            $sent = (int)($point['sent'] ?? 0);
            $received = (int)($point['received'] ?? 0);
            $peak = max($peak, $sent + $received);

            $series[] = [
                'bucket' => (int)($point['bucket'] ?? 0),
                'sent' => $sent,
                'received' => $received,
                'total' => $sent + $received,
            ];
        }

        $sent = (int)($raw['sent'] ?? 0);
        $received = (int)($raw['received'] ?? 0);

        $step = (int)($raw['step'] ?? 3600);

        return [
            'mac' => (string)($raw['mac'] ?? ''),
            'step' => $step,
            'step_name' => $step >= 86400 ? gettext('day') : gettext('hour'),
            'series' => $series,
            'peak' => $peak,
            'peak_text' => Bytes::human($peak),
            'sent' => Bytes::human($sent),
            'received' => Bytes::human($received),
            'total' => Bytes::human($sent + $received),
            'hours' => count($series),
            'interfaces' => array_values(array_filter((array)($raw['interfaces'] ?? []))),
            'busiest' => self::busiest($series),
            'note' => self::note($raw, $series, $now),
        ];
    }

    /**
     * @return array|null the heaviest hour, or null when nothing moved at all
     */
    private static function busiest(array $series): ?array
    {
        $best = null;
        foreach ($series as $point) {
            if ($point['total'] > 0 && ($best === null || $point['total'] > $best['total'])) {
                $best = $point;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'bucket' => $best['bucket'],
            'what' => Bytes::human($best['total']),
        ];
    }

    /**
     * Why the chart may be shorter, or emptier, than the window asked for.
     *
     * Both cases look identical on a chart and mean opposite things, which is
     * the same distinction §4.22 and §4.28 exist for.
     */
    private static function note(array $raw, array $series, int $now): ?string
    {
        if ($series === []) {
            return gettext('Lens has no traffic history at all yet.');
        }

        $asked = (int)($raw['hours'] ?? 0);
        $step = (int)($raw['step'] ?? 3600);
        $have = count($series) * $step;

        if ($asked > 0 && $have < ($asked * 3600) - $step) {
            return sprintf(
                gettext(
                    'Lens has only been collecting for %s, so the chart is that long '
                    . 'rather than the %s asked for.'
                ),
                Duration::span($have),
                Duration::span($asked * 3600)
            );
        }

        return null;
    }
}
