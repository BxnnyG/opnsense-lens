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
            'headline' => sprintf(
                gettext('%s questions asked of Unbound in what it keeps, %s of them blocked.'),
                number_format($total),
                self::pct($blocked['pct'] ?? 0)
            ),
            'top' => self::bars((array)($totals['top'] ?? []), false),
            'blocked' => self::bars((array)($totals['top_blocked'] ?? []), true),
            'clients' => self::clients((array)($raw['clients'] ?? []), $rows),
            'clients_read' => !empty($raw['clients_read']),
        ];
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
