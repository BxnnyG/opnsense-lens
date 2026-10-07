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
 * Class Internet
 *
 * The panel UniFi opens with: is the internet there, what is my address, how
 * far away are the places everybody goes, and when was it last gone. Pure.
 *
 * @package OPNsense\Lens
 */
class Internet
{
    /**
     * @param array $addresses `interface address` decoded -- per interface key,
     *                         element 0 the primary IPv4, element 1 the IPv6,
     *                         exactly as core's own interface overview reads it
     * @param array $probes `lens internet` decoded
     * @param array $line LineQuality's output
     * @param string $wanName what the operator calls the WAN interface
     * @param int $now
     * @return array
     */
    /**
     * @param array $wan Wan::pick() -- which interface carries each protocol
     */
    public static function describe(array $addresses, array $probes, array $line, array $wan, int $now): array
    {
        $wanName = (string)($wan['name'] ?? 'WAN');
        $v4 = (array)($addresses[$wan['v4'] ?? 'wan'] ?? []);
        $v6 = (array)($addresses[$wan['v6'] ?? 'wan'] ?? []);

        return [
            'state' => self::state($probes, $line),
            /* switched off under Services: Lens: Settings (§4.58); the state
               then comes from the gateway alone, as it does before any probe */
            'probing' => (bool)($probes['probing'] ?? true),
            'wan' => [
                'name' => $wanName,
                'ipv4' => self::address($v4[0] ?? null),
                'ipv6' => self::address($v6[1] ?? null),
            ],
            'public' => self::publicAddress(
                is_array($probes['public'] ?? null) ? $probes['public'] : [],
                self::address($v4[0] ?? null),
                $now
            ),
            'probes' => self::probes($probes),
            'uptime' => self::uptime((array)($probes['uptime'] ?? []), $now),
            'step' => (int)($probes['step'] ?? 3600),
            'since' => (int)($probes['since'] ?? $now),
            'now' => $now,
        ];
    }

    /**
     * Online, degraded, down, or unknown -- from the probes first, because they
     * measure the internet itself, and from the gateway when there are none.
     */
    private static function state(array $probes, array $line): array
    {
        $latest = (array)($probes['latest'] ?? []);

        if ($latest !== []) {
            $answered = array_filter($latest, function ($probe) {
                return $probe['rtt'] !== null;
            });
            $lossy = array_filter($latest, function ($probe) {
                return (float)($probe['loss'] ?? 0) > 0;
            });

            if ($answered === []) {
                return ['key' => 'down', 'text' => gettext('Offline')];
            }
            if ($lossy !== [] || ($line['worst'] ?? null) === 'degraded') {
                return ['key' => 'degraded', 'text' => gettext('Online, but losing packets')];
            }
            return ['key' => 'up', 'text' => gettext('Online')];
        }

        switch ($line['worst'] ?? null) {
            case 'down':
                return ['key' => 'down', 'text' => gettext('Offline')];
            case 'degraded':
                return ['key' => 'degraded', 'text' => gettext('Online, but struggling')];
            case 'good':
                return ['key' => 'up', 'text' => gettext('Online')];
            default:
                return ['key' => 'unknown', 'text' => gettext('Not measured yet')];
        }
    }

    /**
     * The address the internet sees, beside the WAN's own (§4.81): the same,
     * behind a modem's NAT, or behind the provider's (CGNAT, 100.64/10) --
     * the last one is why a port forward does not reach this firewall.
     */
    public static function publicAddress(array $public, ?string $wanV4, int $now): ?array
    {
        $v4 = isset($public['v4']) && is_string($public['v4']) ? $public['v4'] : null;
        $v6 = isset($public['v6']) && is_string($public['v6']) ? $public['v6'] : null;
        if ($v4 === null && $v6 === null) {
            return null;
        }

        if ($v4 === null || $wanV4 === null) {
            $relation = ['key' => 'unknown', 'text' => ''];
        } elseif ($v4 === $wanV4) {
            $relation = ['key' => 'same', 'text' => gettext('the WAN address is the public one')];
        } elseif (self::inRange($wanV4, '100.64.0.0', 10)) {
            $relation = ['key' => 'cgnat', 'text' => gettext(
                'the provider shares one public address (CGNAT): forwarded ports will not reach this firewall'
            )];
        } else {
            $relation = ['key' => 'nat', 'text' => gettext('behind another router: the WAN address is a private one')];
        }

        return [
            'ipv4' => $v4,
            'ipv6' => $v6,
            'relation' => $relation,
            'checked' => Duration::ago($now - (int)($public['at'] ?? $now)),
        ];
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        $a = ip2long($address);
        $n = ip2long($network);
        if ($a === false || $n === false) {
            return false;
        }
        $mask = -1 << (32 - $bits);

        return ($a & $mask) === ($n & $mask);
    }

    private static function address($entry): ?string
    {
        if (!is_array($entry) || empty($entry['address'])) {
            return null;
        }

        /* a link-local address is no address on the internet; the second
           firewall's IPv6 WAN showed fe80::... until this said so */
        $address = (string)$entry['address'];
        return stripos($address, 'fe80:') === 0 ? null : $address;
    }

    private static function probes(array $probes): array
    {
        $latest = [];
        foreach ((array)($probes['latest'] ?? []) as $probe) {
            $latest[$probe['target']] = $probe;
        }

        $out = [];
        foreach ((array)($probes['targets'] ?? []) as $target) {
            $now = $latest[$target] ?? null;
            $out[] = [
                'target' => $target,
                'rtt' => $now['rtt'] ?? null,
                'loss' => $now['loss'] ?? null,
                'series' => array_values((array)($probes['series'][$target] ?? [])),
            ];
        }

        return $out;
    }

    private static function uptime(array $uptime, int $now): array
    {
        $outages = [];
        foreach ((array)($uptime['outages'] ?? []) as $outage) {
            $outages[] = [
                'from' => (int)$outage[0],
                'to' => (int)$outage[1],
                'for' => Duration::span((int)$outage[1] - (int)$outage[0]),
                'ago' => Duration::ago($now - (int)$outage[1]),
            ];
        }

        $last = end($outages) ?: null;

        return [
            'percent' => $uptime['up_pct'] ?? null,
            'rounds' => (int)($uptime['rounds'] ?? 0),
            'strip' => array_values((array)($uptime['strip'] ?? [])),
            /* what each slice is made of, for its hover: [start, end, rounds, down, ms] */
            'slots' => array_values(array_map(function ($slot) {
                $slot = array_values((array)$slot);
                return [(int)($slot[0] ?? 0), (int)($slot[1] ?? 0), (int)($slot[2] ?? 0), (int)($slot[3] ?? 0),
                        isset($slot[4]) ? (float)$slot[4] : null];
            }, (array)($uptime['slots'] ?? []))),
            'outages' => array_reverse($outages),
            'last' => $last,
        ];
    }
}
