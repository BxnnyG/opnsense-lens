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
 * Class DnsReport
 *
 * What the network looked up (§4.64): Unbound's own totals, and the devices
 * that asked, named the way every page names them. When there is nothing to
 * show, the reason is the page. Pure.
 *
 * @package OPNsense\Lens
 */
class DnsReport
{
    public const SHOWN = 15;

    /**
     * @param array $raw `lens dns overview` decoded
     * @param array $rows DeviceReport's device rows
     * @param array $unbound ['enabled' => bool, 'stats' => bool] from the configuration
     * @return array
     */
    public static function describe(array $raw, array $rows, array $unbound): array
    {
        $state = self::state($raw, $unbound);
        if ($state['key'] !== 'ok') {
            return ['state' => $state, 'figures' => null, 'top' => [], 'blocked' => [], 'clients' => []];
        }

        $totals = (array)$raw['totals'];
        $total = (int)($totals['total'] ?? 0);
        $blocked = (array)($totals['blocked'] ?? []);

        return [
            'state' => $state,
            'figures' => [
                'total' => number_format($total),
                'blocked' => number_format((int)($blocked['total'] ?? 0)),
                'blocked_pct' => self::pct($blocked['pct'] ?? 0),
                'resolved_pct' => self::pct($totals['resolved']['pct'] ?? 0),
                'local_pct' => self::pct($totals['local']['pct'] ?? 0),
                'blocklist' => number_format((int)($totals['blocklist_size'] ?? 0)),
                'since' => $totals['start_time'] ?? null,
            ],
            'headline' => ($raw['source'] ?? 'stats') === 'store'
                ? sprintf(
                    gettext('%s questions asked of Unbound in the last %s, %s of them blocked.'),
                    number_format($total),
                    (int)($raw['hours'] ?? 24) > 24 ? gettext('7 days') : gettext('24 hours'),
                    self::pct($blocked['pct'] ?? 0)
                )
                : sprintf(
                    gettext('%s questions asked of Unbound in what it keeps, %s of them blocked.'),
                    number_format($total),
                    self::pct($blocked['pct'] ?? 0)
                ),
            'top' => self::bars((array)($totals['top'] ?? []), false),
            'blocked' => self::bars((array)($totals['top_blocked'] ?? []), true),
            'clients' => self::clients((array)($raw['clients'] ?? []), $rows),
            'clients_read' => !empty($raw['clients_read']),
            /* from Unbound's own store (§4.70): every question of the range */
            'source' => (string)($raw['source'] ?? 'stats'),
            'hours' => (int)($raw['hours'] ?? 24),
            'by_device' => self::byDevice((array)($raw['devices'] ?? []), $rows),
            'by_name' => self::byName((array)($raw['names'] ?? []), $rows),
        ];
    }

    /** mac to its row, every MAC of a folded phone included */
    private static function index(array $rows): array
    {
        $byMac = [];
        foreach ($rows as $row) {
            foreach ((array)($row['macs'] ?? [$row['mac']]) as $mac) {
                $byMac[$mac] = $row;
            }
        }

        return $byMac;
    }

    /**
     * Who asked what: each device, its questions, how many were blocked, and
     * the names it asked most. A folded phone is one line.
     */
    private static function byDevice(array $devices, array $rows): array
    {
        $byMac = self::index($rows);
        $merged = [];
        foreach ($devices as $device) {
            $row = $byMac[$device['mac'] ?? ''] ?? null;
            $key = $row['mac'] ?? (string)($device['mac'] ?? '');
            if (!isset($merged[$key])) {
                $merged[$key] = ['row' => $row, 'mac' => $key, 'queries' => 0, 'blocked' => 0, 'names' => 0,
                                 'domains' => []];
            }
            $merged[$key]['queries'] += (int)($device['queries'] ?? 0);
            $merged[$key]['blocked'] += (int)($device['blocked'] ?? 0);
            $merged[$key]['names'] += (int)($device['names'] ?? 0);
            foreach ((array)($device['domains'] ?? []) as $domain) {
                $name = (string)$domain['domain'];
                $merged[$key]['domains'][$name] = [
                    'count' => ($merged[$key]['domains'][$name]['count'] ?? 0) + (int)$domain['count'],
                    'blocked' => ($merged[$key]['domains'][$name]['blocked'] ?? 0) + (int)($domain['blocked'] ?? 0),
                    'blocklist' => $domain['blocklist'] ?? null,
                ];
            }
        }
        usort($merged, function ($left, $right) {
            return $right['queries'] <=> $left['queries'];
        });
        $largest = max(1, $merged[0]['queries'] ?? 1);

        return array_map(function ($entry) use ($largest) {
            uasort($entry['domains'], function ($left, $right) {
                return $right['count'] <=> $left['count'];
            });
            $domains = [];
            foreach (array_slice($entry['domains'], 0, 10, true) as $name => $domain) {
                $domains[] = [
                    'domain' => $name,
                    'count' => number_format($domain['count']),
                    'blocked' => $domain['blocked'] > 0,
                    'blocklist' => $domain['blocklist'],
                ];
            }

            return [
                'name' => $entry['row']['name'] ?? $entry['mac'],
                'link' => $entry['row'] !== null ? '/ui/lens/device?mac=' . rawurlencode($entry['mac']) : null,
                'icon' => $entry['row']['kind']['icon'] ?? 'fa-circle-o',
                'interfaces' => array_values((array)($entry['row']['interfaces'] ?? [])),
                'tags' => array_values((array)($entry['row']['tags'] ?? [])),
                'queries' => number_format($entry['queries']),
                'blocked' => number_format($entry['blocked']),
                'blocked_pct' => self::pct($entry['queries'] ? $entry['blocked'] / $entry['queries'] * 100 : 0),
                'names' => number_format($entry['names']),
                'bar' => round($entry['queries'] / $largest * 100, 1),
                'domains' => $domains,
            ];
        }, $merged);
    }

    /** What was asked, and by whom: each name with the devices that asked it most. */
    private static function byName(array $names, array $rows): array
    {
        $byMac = self::index($rows);
        $largest = max(1, (int)($names[0]['count'] ?? 1));

        return array_map(function ($name) use ($byMac, $largest) {
            $askers = [];
            foreach ((array)($name['askers'] ?? []) as $asker) {
                $row = $asker['mac'] !== null ? ($byMac[$asker['mac']] ?? null) : null;
                $askers[] = [
                    'name' => $row['name'] ?? ($asker['address'] !== null
                        ? sprintf(gettext('%s (no device then)'), $asker['address'])
                        : (string)$asker['mac']),
                    'count' => number_format((int)$asker['count']),
                ];
            }

            return [
                'domain' => (string)$name['domain'],
                'count' => number_format((int)$name['count']),
                'blocked' => (int)($name['blocked'] ?? 0) > 0,
                'blocked_count' => number_format((int)($name['blocked'] ?? 0)),
                'blocklist' => $name['blocklist'] ?? null,
                'bar' => round((int)$name['count'] / $largest * 100, 1),
                'askers' => $askers,
            ];
        }, $names);
    }

    /** the one sentence that replaces the page when there is nothing on it */
    private static function state(array $raw, array $unbound): array
    {
        if (empty($unbound['enabled'])) {
            return ['key' => 'no_unbound', 'text' => gettext(
                'This firewall does not resolve with Unbound. Only Unbound keeps a record of what was looked up '
                . '- dnsmasq keeps none - so there is nothing here to show.'
            )];
        }
        if (empty($unbound['stats'])) {
            return ['key' => 'no_stats', 'text' => gettext(
                'Unbound resolves here but does not record the questions. Switching on Services: Unbound DNS: '
                . 'Statistics starts the record; Lens does not switch it on for you.'
            ), 'link' => '/ui/unbound/stats'];
        }
        if (empty($raw['available'])) {
            return ['key' => 'unreadable', 'text' => gettext(
                'Unbound\'s statistics did not answer. Reporting: Unbound DNS reads the same store and may say why.'
            ), 'link' => '/ui/unbound/overview'];
        }
        if (($raw['reason'] ?? null) === 'empty') {
            return ['key' => 'empty', 'text' => gettext(
                'Unbound records, but nothing has been asked yet - or the devices here ask another resolver.'
            )];
        }

        return ['key' => 'ok', 'text' => ''];
    }

    private static function pct($value): string
    {
        return rtrim(rtrim(number_format((float)$value, 1), '0'), '.') . '%';
    }

    private static function bars(array $entries, bool $blocked): array
    {
        $entries = array_slice($entries, 0, self::SHOWN);
        $largest = max(array_merge([1], array_map(function ($entry) {
            return (int)($entry['count'] ?? 0);
        }, $entries)));

        return array_map(function ($entry) use ($largest, $blocked) {
            return [
                'domain' => rtrim((string)($entry['domain'] ?? ''), '.'),
                'count' => number_format((int)($entry['count'] ?? 0)),
                'share' => self::pct($entry['pct'] ?? 0),
                'bar' => round((int)($entry['count'] ?? 0) / $largest * 100, 1),
                'blocklist' => $blocked ? ($entry['blocklist'] ?? null) : null,
            ];
        }, $entries);
    }

    /**
     * Devices by the questions they asked in the last day. A folded phone is one
     * device; an address nobody Lens saw held at the time stays an address.
     */
    private static function clients(array $clients, array $rows): array
    {
        $byMac = [];
        foreach ($rows as $row) {
            foreach ((array)($row['macs'] ?? [$row['mac']]) as $mac) {
                $byMac[$mac] = $row;
            }
        }

        $devices = [];
        foreach ($clients as $client) {
            $mac = $client['mac'] ?? null;
            $row = $mac === null ? null : ($byMac[$mac] ?? null);
            $key = $row !== null ? 'd:' . $row['mac'] : 'a:' . implode(',', (array)($client['addresses'] ?? []));
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'name' => $row['name'] ?? implode(', ', (array)($client['addresses'] ?? [])),
                    'mac' => $row['mac'] ?? $mac,
                    'placed' => $row !== null,
                    'link' => $row !== null ? '/ui/lens/device?mac=' . rawurlencode($row['mac']) : null,
                    'icon' => $row['kind']['icon'] ?? 'fa-question-circle-o',
                    'interfaces' => array_values((array)($row['interfaces'] ?? [])),
                    'tags' => array_values((array)($row['tags'] ?? [])),
                    'queries' => 0,
                    'addresses' => [],
                ];
            }
            $devices[$key]['queries'] += (int)($client['queries'] ?? 0);
            $devices[$key]['addresses'] = array_values(array_unique(array_merge(
                $devices[$key]['addresses'],
                (array)($client['addresses'] ?? [])
            )));
        }

        usort($devices, function ($left, $right) {
            return $right['queries'] <=> $left['queries'];
        });
        $devices = array_slice($devices, 0, self::SHOWN);
        $largest = max(1, $devices[0]['queries'] ?? 1);

        foreach ($devices as &$device) {
            $device['bar'] = round($device['queries'] / $largest * 100, 1);
            $device['sub'] = $device['placed']
                ? implode(', ', $device['addresses'])
                : gettext('no device Lens saw held this address then');
            $device['queries'] = number_format($device['queries']);
        }
        unset($device);

        return $devices;
    }
}
