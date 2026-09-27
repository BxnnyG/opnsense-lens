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
 * Class Palette
 *
 * What Ctrl-K can find (§4.65): every device as every page names it, Lens's
 * own pages, and the networks. Core's menu search already finds core's pages.
 * Pure.
 *
 * @package OPNsense\Lens
 */
class Palette
{
    /**
     * @param array $rows DeviceReport's device rows
     * @param array $segments interface to the operator's name for it
     * @return array
     */
    public static function index(array $rows, array $segments): array
    {
        $devices = [];
        $used = [];
        foreach ($rows as $row) {
            $address = $row['addresses'][0] ?? null;
            foreach ((array)($row['interfaces'] ?? []) as $interface) {
                $used[$interface] = true;
            }
            $devices[] = [
                'name' => (string)$row['name'],
                'sub' => implode(' · ', array_filter([
                    $row['vendor'] ?? null,
                    $address['address'] ?? null,
                    $address['segment'] ?? null,
                    $row['mac'],
                ])),
                'url' => '/ui/lens/device?mac=' . rawurlencode((string)$row['mac']),
                'icon' => $row['kind']['icon'] ?? 'fa-circle-o',
                'here' => !empty($row['here']),
                /* DeviceReport's own haystack, plus every MAC of a folded phone */
                'haystack' => strtolower(trim(
                    ($row['haystack'] ?? '') . ' ' . implode(' ', (array)($row['macs'] ?? []))
                )),
            ];
        }

        $networks = [];
        foreach (array_keys($used) as $interface) {
            $name = $segments[$interface] ?? $interface;
            $networks[] = [
                'name' => $name,
                'sub' => $interface,
                'url' => '/ui/lens/overview?segment=' . rawurlencode($interface),
                'icon' => 'fa-sitemap',
                'haystack' => strtolower($name . ' ' . $interface),
            ];
        }
        usort($networks, function ($left, $right) {
            return strcmp($left['name'], $right['name']);
        });

        return ['pages' => self::pages(), 'networks' => $networks, 'devices' => $devices];
    }

    private static function pages(): array
    {
        $pages = [
            ['/ui/lens/dashboard', 'fa-tachometer', gettext('Dashboard'), 'home overview internet'],
            ['/ui/lens/events', 'fa-bell', gettext('Events'), 'what happened alerts outage new'],
            ['/ui/lens/overview', 'fa-laptop', gettext('Devices'), 'clients list'],
            ['/ui/lens/presence', 'fa-home', gettext("Who's home"), 'presence here'],
            ['/ui/lens/segments', 'fa-sitemap', gettext('Networks'), 'segments vlan'],
            ['/ui/lens/dns', 'fa-globe', gettext('DNS'), 'unbound queries blocked domains'],
            ['/ui/lens/wall', 'fa-television', gettext('Wallboard'), 'kiosk screen'],
            ['/ui/lens/preflight', 'fa-plug', gettext('Data Sources'), 'netflow sources health'],
            ['/ui/lens/settings', 'fa-sliders', gettext('Settings'), 'retention purge probes'],
        ];

        return array_map(function ($page) {
            return [
                'name' => $page[2],
                'sub' => gettext('Lens page'),
                'url' => $page[0],
                'icon' => $page[1],
                'haystack' => strtolower($page[2] . ' ' . $page[3]),
            ];
        }, $pages);
    }
}
