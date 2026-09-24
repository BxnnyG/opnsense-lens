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
 * Class DeviceProfile
 *
 * The device page: one device's row from DeviceReport, its week, and its life
 * story -- every address and segment it has held, with dates. Pure.
 *
 * @package OPNsense\Lens
 */
class DeviceProfile
{
    /**
     * @param array $row the device's row from DeviceReport
     * @param array $raw what `lens profile` returned
     * @param int|null $observedAt the last observation
     * @param int $now
     * @return array
     */
    public static function describe(array $row, array $raw, ?int $observedAt, int $now): array
    {
        return [
            'device' => $row,
            'heatmap' => Heatmap::grid((array)($raw['heatmap'] ?? [])),
            'heatmap_days' => (int)($raw['heatmap_days'] ?? 28),
            'story' => self::story((array)($raw['windows'] ?? []), $observedAt, $now),
            'facts' => self::facts($row, (array)($raw['windows'] ?? []), $now),
        ];
    }

    /**
     * One line per address it held, newest first. Each carries how many times
     * the device came back to it: a phone that holds the same lease for a month
     * across sixty visits is one line with "60 visits", not sixty lines.
     */
    private static function story(array $windows, ?int $observedAt, int $now): array
    {
        $held = [];
        foreach ($windows as $window) {
            $key = ($window['address'] ?? '') . '@' . ($window['interface'] ?? '');
            if (!isset($held[$key])) {
                $held[$key] = [
                    'address' => (string)($window['address'] ?? ''),
                    'interface' => (string)($window['interface'] ?? ''),
                    'from' => (int)($window['first_seen'] ?? 0),
                    'to' => (int)($window['last_seen'] ?? 0),
                    'visits' => 0,
                ];
            }
            $held[$key]['from'] = min($held[$key]['from'], (int)($window['first_seen'] ?? 0));
            $held[$key]['to'] = max($held[$key]['to'], (int)($window['last_seen'] ?? 0));
            $held[$key]['visits']++;
        }

        $story = [];
        foreach ($held as $entry) {
            $entry['current'] = $observedAt !== null && $entry['to'] >= $observedAt;
            $entry['from_text'] = date('j M Y, H:i', $entry['from']);
            $entry['to_text'] = $entry['current'] ? gettext('now') : Duration::ago($now - $entry['to']);
            $story[] = $entry;
        }

        usort($story, function ($left, $right) {
            if ($left['current'] !== $right['current']) {
                return $left['current'] ? -1 : 1;
            }
            return $right['to'] <=> $left['to'];
        });

        return $story;
    }

    private static function facts(array $row, array $windows, int $now): array
    {
        $segments = [];
        foreach ($windows as $window) {
            $segments[(string)($window['interface'] ?? '')] = true;
        }

        return [
            'first_seen' => $row['first_seen'] ? date('j M Y', (int)$row['first_seen']) : null,
            'known_for' => $row['known_for'] ?? null,
            'visits' => count($windows),
            'segments' => count($segments),
            'addresses' => count($row['addresses'] ?? []),
        ];
    }
}
