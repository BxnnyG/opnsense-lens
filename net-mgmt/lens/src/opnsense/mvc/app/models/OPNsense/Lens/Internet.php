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
    public static function describe(array $addresses, array $probes, array $line, string $wanName, int $now): array
    {
        $wan = (array)($addresses['wan'] ?? []);

        return [
            'state' => self::state($probes, $line),
            'wan' => [
                'name' => $wanName,
                'ipv4' => self::address($wan[0] ?? null),
                'ipv6' => self::address($wan[1] ?? null),
            ],
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

    private static function address($entry): ?string
    {
        if (!is_array($entry) || empty($entry['address'])) {
            return null;
        }

        return (string)$entry['address'];
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
            'outages' => array_reverse($outages),
            'last' => $last,
        ];
    }
}
