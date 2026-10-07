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
 * Class Wan
 *
 * Which interface is the internet, per protocol. Pure.
 *
 * Shipped first as "the interface whose key is `wan`". The operator's second
 * firewall has its WAN under `opt18`, and IPv4 leaves through `igb1` while IPv6
 * leaves through `igb0` -- two interfaces, neither of them keyed `wan`. The
 * panel showed no address and no rate. The internet is wherever the default
 * route points, so that is what this reads (§4.75).
 *
 * @package OPNsense\Lens
 */
class Wan
{
    /**
     * @param array $routes `interface routes list -n json` decoded
     * @param array $interfaces config key to ['if' => device, 'descr' => name]
     * @return array ['v4' => key|null, 'v6' => key|null, 'name' => text]
     */
    public static function pick(array $routes, array $interfaces): array
    {
        $byDevice = [];
        foreach ($interfaces as $key => $interface) {
            if (!empty($interface['if'])) {
                $byDevice[$interface['if']] = $key;
            }
        }

        $found = ['v4' => null, 'v6' => null];
        foreach ($routes as $route) {
            if (!is_array($route) || ($route['destination'] ?? '') !== 'default') {
                continue;
            }
            $proto = ($route['proto'] ?? '') === 'ipv6' ? 'v6' : 'v4';
            if ($found[$proto] === null && isset($byDevice[$route['netif'] ?? ''])) {
                $found[$proto] = $byDevice[$route['netif']];
            }
        }

        /* no default route at all: fall back to the conventional key, which is
           still right on most boxes and is at least a guess that says so */
        if ($found['v4'] === null && $found['v6'] === null && isset($interfaces['wan'])) {
            $found['v4'] = 'wan';
            $found['v6'] = 'wan';
        }

        $key = $found['v4'] ?? $found['v6'];
        $name = $key !== null && !empty($interfaces[$key]['descr'])
            ? (string)$interfaces[$key]['descr']
            : strtoupper((string)($key ?? 'wan'));

        return $found + ['name' => $name];
    }
}
