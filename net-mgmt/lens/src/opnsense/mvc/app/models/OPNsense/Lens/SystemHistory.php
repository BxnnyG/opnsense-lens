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
 * Class SystemHistory
 *
 * The firewall's own history, as core records it in /var/db/rrd and System:
 * Health draws it (stage 53, §4.84 phase 4) -- read, never stored twice. One
 * RRD file holds the same series at several resolutions; the range decides
 * which. Pure.
 *
 * @package OPNsense\Lens
 */
class SystemHistory
{
    /** at most this many points per line: a chart wider than a screen shows nothing more */
    public const POINTS = 400;

    /**
     * @param array $fetch `health fetch <file>` decoded
     * @param int $hours the range asked for
     * @param int $now
     * @return array ['step' => seconds, 'series' => [['key', 'points' => [[unix, value|null]]]]]
     */
    public static function series(array $fetch, int $hours, int $now): array
    {
        $sets = array_values(array_filter((array)($fetch['sets'] ?? []), 'is_array'));
        if ($sets === []) {
            return ['step' => 0, 'series' => []];
        }
        $range = $hours * 3600;

        /* the finest archive that still reaches back over the whole range,
           else the one that reaches furthest */
        usort($sets, function ($left, $right) {
            return (int)($left['step_size'] ?? 0) <=> (int)($right['step_size'] ?? 0);
        });
        $chosen = null;
        foreach ($sets as $set) {
            if ((int)($set['recorded_time'] ?? 0) >= $range) {
                $chosen = $set;
                break;
            }
        }
        if ($chosen === null) {
            usort($sets, function ($left, $right) {
                return (int)($right['recorded_time'] ?? 0) <=> (int)($left['recorded_time'] ?? 0);
            });
            $chosen = $sets[0];
        }

        $since = $now - $range;
        $series = [];
        foreach ((array)($chosen['ds'] ?? []) as $ds) {
            $points = [];
            foreach ((array)($ds['values'] ?? []) as $value) {
                $at = (int)floor(((float)($value[0] ?? 0)) / 1000);
                if ($at >= $since && $at <= $now) {
                    $points[] = [$at, isset($value[1]) ? round((float)$value[1], 3) : null];
                }
            }
            $series[] = ['key' => (string)($ds['key'] ?? ''), 'points' => self::thin($points)];
        }

        return ['step' => (int)($chosen['step_size'] ?? 0), 'series' => $series];
    }

    /**
     * Which RRD files the System page draws: processor, memory, and anything
     * named for temperature -- from `health list`, as the box has them.
     *
     * @param array $list `health list` decoded: name => ['topic', 'itemName', 'filename', 'title', ...]
     * @return array key => ['filename', 'title', 'units']
     */
    public static function chosen(array $list): array
    {
        $out = [];
        foreach ($list as $name => $item) {
            $item = (array)$item;
            $key = null;
            $item_name = (string)($item['itemName'] ?? '');
            if (($item['topic'] ?? '') === 'system' && in_array($item_name, ['processor', 'memory'], true)) {
                $key = $item_name;
            } elseif (stripos((string)$name, 'temp') !== false || stripos($item_name, 'temp') !== false) {
                $key = 'temperature';
            }
            if ($key === null || isset($out[$key]) || empty($item['filename'])) {
                continue;
            }
            $out[$key] = [
                'filename' => (string)$item['filename'],
                'title' => (string)($item['title'] ?? '') !== '' ? (string)$item['title'] : ucfirst($key),
                'units' => array_map('strval', (array)($item['field_units'] ?? [])),
            ];
        }

        return $out;
    }

    /** every n-th point, so a week at one minute is not ten thousand of them */
    private static function thin(array $points): array
    {
        $count = count($points);
        if ($count <= self::POINTS) {
            return $points;
        }
        $every = (int)ceil($count / self::POINTS);
        $out = [];
        for ($i = 0; $i < $count; $i += $every) {
            $out[] = $points[$i];
        }

        return $out;
    }
}
