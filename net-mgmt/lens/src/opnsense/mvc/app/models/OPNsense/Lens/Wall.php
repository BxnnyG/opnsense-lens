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
 * Class Wall
 *
 * What the wall shows, composed from what the pages already say (§4.71): the
 * device list's figures and sentence, today's events, who is home, the line
 * and the network's day. Nothing here is computed differently from its page;
 * this only chooses and trims, so the screen nobody stands at cannot disagree
 * with the page somebody opens.
 *
 * @package OPNsense\Lens
 */
class Wall
{
    /** rows the board may show; it shows as many as fit */
    public const TOP = 14;

    /** the "just now" list; older ones have faded out anyway */
    public const EVENTS = 12;

    /**
     * @param array $list DevicesController::report() over 24 hours
     * @param array $events Events::describe() over a week: the list runs out
     *                      into the past, the figure counts the last day
     * @param array $people PresenceReport's people
     * @param array $internet Internet::describe()
     * @param array $timeline DashboardController::timeline() over 24 hours
     * @param int $now
     * @return array
     */
    public static function describe(
        array $list,
        array $events,
        array $people,
        array $internet,
        array $timeline,
        int $now
    ): array {
        $summary = (array)($list['summary'] ?? []);
        $compare = (array)($list['compare'] ?? []);
        $shown = array_values(array_filter((array)($events['events'] ?? []), function ($event) {
            return empty($event['muted']);
        }));

        return [
            'now' => $now,
            'stale' => !empty($list['stale']),
            'note' => (string)($list['note'] ?? ''),
            'sentence' => (array)($list['sentence'] ?? []),
            'figures' => [
                'here' => (int)($summary['here'] ?? 0),
                'known' => (int)($summary['known'] ?? 0),
                'moved' => (string)($summary['moved'] ?? ''),
                /* against the same hours the day before, as the dashboard says it */
                'change' => !empty($compare['covered']) ? (array)$compare['total'] : null,
                'change_label' => !empty($compare['covered']) ? (string)$compare['label'] : '',
                'new' => !empty($summary['new_yet']) ? count((array)($summary['new'] ?? [])) : null,
                'watching_for' => (string)($summary['watching_for'] ?? ''),
                'events' => count(array_filter($shown, function ($event) use ($now) {
                    return (int)$event['at'] >= $now - 86400;
                })),
            ],
            'events' => array_map(function ($event) use ($now) {
                $at = (int)$event['at'];
                return [
                    'kind' => (string)$event['kind'],
                    'tone' => (string)$event['tone'],
                    'word' => (string)$event['word'],
                    'title' => (string)$event['title'],
                    'at' => $at,
                    /* an unusual day is a day, not a moment: the board writes its date */
                    'grain' => (string)($event['grain'] ?? 'moment'),
                    'ago' => ($event['grain'] ?? 'moment') === 'moment' ? Duration::ago($now - $at) : '',
                ];
            }, array_slice($shown, 0, self::EVENTS)),
            'people' => array_map(function ($person) {
                return ['name' => (string)$person['name'], 'here' => (bool)$person['here']];
            }, $people),
            'internet' => [
                'state' => (array)($internet['state'] ?? ['key' => 'unknown', 'text' => '']),
                'probes' => array_map(function ($probe) {
                    return ['target' => $probe['target'], 'rtt' => $probe['rtt'], 'loss' => $probe['loss']];
                }, (array)($internet['probes'] ?? [])),
                'uptime' => $internet['uptime']['percent'] ?? null,
            ],
            'timeline' => [
                'series' => (array)($timeline['series'] ?? []),
                'step' => (int)($timeline['step'] ?? 3600),
                'peak' => (int)($timeline['peak'] ?? 0),
                'peak_text' => (string)($timeline['peak_text'] ?? ''),
            ],
            'top' => array_slice(
                self::fold((array)($list['devices'] ?? []), (array)($list['groups'] ?? [])),
                0,
                self::TOP
            ),
        ];
    }

    /**
     * Eight rows reading "Proxmox Server Solutions GmbH..." are eight rows
     * saying nothing: a herd the Devices page folds is one row here too, under
     * the group the page hands over, never a rule of the wall's own.
     *
     * @param array $devices DeviceReport's rows
     * @param array $groups DeviceReport's groups
     * @return array rows with traffic, heaviest first
     */
    public static function fold(array $devices, array $groups): array
    {
        $byKey = [];
        foreach ($groups as $group) {
            $byKey[$group['key']] = $group;
        }

        $rows = [];
        foreach ($devices as $device) {
            $group = $byKey[$device['group'] ?? ''] ?? null;
            if ($group === null) {
                $rows['device:' . $device['mac']] = [
                    'name' => (string)$device['name'],
                    'icon' => (string)($device['kind']['icon'] ?? 'fa-circle-o'),
                    'octets' => (int)($device['octets'] ?? 0),
                ];
                continue;
            }
            $key = 'group:' . $group['key'];
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'name' => $group['label'] . " \u{00d7} " . $group['count'],
                    'icon' => (string)($group['icon'] ?? 'fa-circle-o'),
                    'octets' => 0,
                ];
            }
            $rows[$key]['octets'] += (int)($device['octets'] ?? 0);
        }

        $rows = array_values(array_filter($rows, function ($row) {
            return $row['octets'] > 0;
        }));
        usort($rows, function ($left, $right) {
            return $right['octets'] <=> $left['octets'] ?: strcmp($left['name'], $right['name']);
        });

        return array_map(function ($row) {
            return $row + ['text' => Bytes::human($row['octets'])];
        }, $rows);
    }
}
