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
 * Class ServiceReport
 *
 * Which services the devices asked for (#44, §4.78): the collector's suffix map
 * laid over Unbound's questions, named the way every page names a device. A
 * phone whose MACs are folded is one device here too. What the map cannot see
 * is said with it, every time. Pure.
 *
 * @package OPNsense\Lens
 */
class ServiceReport
{
    public const ASKERS = 4;

    /** @return array kind => heading, in the order the page shows them */
    public static function kinds(): array
    {
        return [
            'streaming' => gettext('Streaming'),
            'social' => gettext('Social'),
            'messaging' => gettext('Messaging and calls'),
            'games' => gettext('Games'),
            'shopping' => gettext('Shopping'),
            'work' => gettext('Work and tools'),
            'platform' => gettext('Asked by the devices themselves'),
        ];
    }

    /**
     * @param array $raw the collector's 'services': ['services' => [...], 'matched' => int, 'total' => int]
     * @param array $rows DeviceReport's device rows
     * @return array ['groups' => [['kind', 'title', 'services' => [...]]], 'coverage' => string|null]
     */
    public static function network(array $raw, array $rows): array
    {
        $byMac = [];
        foreach ($rows as $row) {
            foreach ((array)($row['macs'] ?? [$row['mac']]) as $mac) {
                $byMac[$mac] = $row;
            }
        }

        $cards = [];
        $series = (array)($raw['series'] ?? []);
        $largest = max(array_merge([1], array_map(function ($service) {
            return (int)($service['queries'] ?? 0);
        }, (array)($raw['services'] ?? []))));
        foreach ((array)($raw['services'] ?? []) as $service) {
            $askers = [];
            foreach ((array)($service['askers'] ?? []) as $asker) {
                $row = ($asker['mac'] ?? null) !== null ? ($byMac[$asker['mac']] ?? null) : null;
                $key = $row !== null ? 'd:' . $row['mac']
                    : (($asker['mac'] ?? null) !== null ? 'm:' . $asker['mac'] : 'a:' . $asker['address']);
                if (!isset($askers[$key])) {
                    $askers[$key] = [
                        'name' => $row['name'] ?? ($asker['address'] !== null
                            ? sprintf(gettext('%s (no device then)'), $asker['address'])
                            : (string)$asker['mac']),
                        'link' => $row !== null ? '/ui/lens/device?mac=' . rawurlencode($row['mac']) : null,
                        'icon' => $row['kind']['icon'] ?? 'fa-question-circle-o',
                        'interfaces' => array_values((array)($row['interfaces'] ?? [])),
                        'tags' => array_values((array)($row['tags'] ?? [])),
                        'placed' => ($asker['mac'] ?? null) !== null,
                        'count' => 0,
                    ];
                }
                $askers[$key]['count'] += (int)$asker['count'];
            }
            usort($askers, function ($left, $right) {
                return $right['count'] <=> $left['count'];
            });
            $devices = count(array_filter($askers, function ($asker) {
                return $asker['placed'];
            }));
            $addresses = count($askers) - $devices;

            $cards[] = [
                'service' => (string)$service['service'],
                'name' => (string)$service['name'],
                'kind' => (string)$service['kind'],
                'icon' => (string)$service['icon'],
                'queries' => number_format((int)$service['queries']),
                'blocked' => (int)($service['blocked'] ?? 0) > 0
                    ? number_format((int)$service['blocked']) : null,
                'devices' => $devices,
                'sub' => self::who($devices, $addresses),
                /* how much, against the busiest service, and when: questions per hour (§4.78) */
                'bar' => round((int)$service['queries'] / $largest * 100, 1),
                'series' => array_map('intval', (array)($series[(string)$service['service']] ?? [])),
                'askers' => array_map(function ($asker) {
                    $asker['count'] = number_format($asker['count']);
                    return $asker;
                }, array_slice($askers, 0, self::ASKERS)),
                'more' => max(0, count($askers) - self::ASKERS),
                /* for the filter: every interface and tag of every device that asked */
                'interfaces' => array_values(array_unique(array_merge([], ...array_map(function ($asker) {
                    return $asker['interfaces'];
                }, $askers)))),
                'tags' => array_values(array_unique(array_merge([], ...array_map(function ($asker) {
                    return $asker['tags'];
                }, $askers)))),
                'sort' => [count($askers), (int)$service['queries']],
            ];
        }

        $groups = [];
        foreach (self::kinds() as $kind => $title) {
            $mine = array_values(array_filter($cards, function ($card) use ($kind) {
                return $card['kind'] === $kind;
            }));
            if ($mine === []) {
                continue;
            }
            usort($mine, function ($left, $right) {
                return $right['sort'] <=> $left['sort'];
            });
            $groups[] = ['kind' => $kind, 'title' => $title, 'services' => array_map(function ($card) {
                unset($card['sort']);
                return $card;
            }, $mine)];
        }

        $total = (int)($raw['total'] ?? 0);

        return [
            'groups' => $groups,
            'coverage' => $total > 0 ? sprintf(
                gettext('%s of the questions belong to a service Lens knows by name; the rest stay names.'),
                self::pct((int)($raw['matched'] ?? 0) / $total * 100)
            ) : null,
        ];
    }

    /**
     * One device's services, as chips: most asked first.
     *
     * @param array $services the collector's per-device list
     * @return array [['name', 'icon', 'kind', 'queries', 'blocked']]
     */
    public static function chips(array $services): array
    {
        return array_map(function ($service) {
            return [
                'service' => (string)$service['service'],
                'name' => (string)$service['name'],
                'icon' => (string)$service['icon'],
                'kind' => (string)$service['kind'],
                'platform' => ($service['kind'] ?? '') === 'platform',
                'queries' => number_format((int)$service['queries']),
                'blocked' => (int)($service['blocked'] ?? 0) > 0,
            ];
        }, $services);
    }

    /** the limits, said wherever services are shown */
    public static function limits(): string
    {
        return gettext(
            'Read from the names the devices asked Unbound for, through a fixed list of domains per service. '
            . 'Asking is not watching: an app checks in, a link previews, a page embeds a video. Approximate behind '
            . 'shared CDNs, and blind to anything a device resolved elsewhere - DNS over HTTPS, another resolver, '
            . 'a VPN.'
        );
    }

    private static function who(int $devices, int $addresses): string
    {
        $parts = [];
        if ($devices > 0) {
            $parts[] = $devices === 1 ? gettext('1 device') : sprintf(gettext('%d devices'), $devices);
        }
        if ($addresses > 0) {
            $parts[] = $addresses === 1
                ? gettext('1 address no device held')
                : sprintf(gettext('%d addresses no device held'), $addresses);
        }

        return implode(', ', $parts);
    }

    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
    }
}
