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
 * A phone that rotates its private MAC is one device, when the evidence says so
 * (DESIGN §4.61).
 *
 * Pure. It reads what `lens devices` returned and says which MACs belong
 * together; DeviceReport does the folding. Nothing in the store is rewritten:
 * this is derived on every read, and one setting switches it off.
 *
 * The evidence, all of it required:
 *   - every MAC is randomised (locally administered); a burned-in address is
 *     never rotated, so it is never folded
 *   - they announce the same hostname, and it is not one a thousand devices
 *     share
 *   - they held an address on at least one common segment
 *   - no two of them ever held an address at the same time
 *   - the operator has not given two of them different names
 *
 * @package OPNsense\Lens
 */
class IdentityFold
{
    /**
     * Names too common to be evidence of anything. A hostname of "iPhone" is
     * every iPhone whose owner never named it; "android" and "*" are what a
     * device announces when it announces nothing.
     */
    public const GENERIC = [
        '', '*', 'iphone', 'ipad', 'android', 'localhost', 'wlan0', 'unknown', 'espressif', 'esp32',
    ];

    /**
     * @param array $devices `lens devices` decoded
     * @return array list of ['primary', 'macs' (newest first), 'hostname', 'first_seen']
     *               for every set of two or more MACs that are one device
     */
    public static function groups(array $devices): array
    {
        $byName = [];
        foreach ($devices as $device) {
            if (
                !is_array($device) || empty($device['mac']) || empty($device['randomised'])
                || !empty($device['is_local'])
            ) {
                continue;
            }
            $name = strtolower(trim((string)($device['hostname'] ?? '')));
            if (in_array($name, self::GENERIC, true)) {
                continue;
            }
            $byName[$name][] = $device;
        }

        $groups = [];
        foreach ($byName as $name => $candidates) {
            if (count($candidates) < 2) {
                continue;
            }
            usort($candidates, function ($left, $right) {
                return ((int)($left['first_seen'] ?? 0)) <=> ((int)($right['first_seen'] ?? 0));
            });

            /* chains: each MAC joins the first chain it could be a continuation
               of, or starts its own. Two phones of one model that were ever
               home together end up in two chains and stay there. */
            $chains = [];
            foreach ($candidates as $device) {
                $placed = false;
                foreach ($chains as &$chain) {
                    if (self::fits($device, $chain)) {
                        $chain[] = $device;
                        $placed = true;
                        break;
                    }
                }
                unset($chain);
                if (!$placed) {
                    $chains[] = [$device];
                }
            }

            foreach ($chains as $chain) {
                if (count($chain) < 2) {
                    continue;
                }
                usort($chain, function ($left, $right) {
                    return ((int)($right['last_seen'] ?? 0)) <=> ((int)($left['last_seen'] ?? 0));
                });
                $groups[] = [
                    'primary' => (string)$chain[0]['mac'],
                    'macs' => array_map(function ($device) {
                        return (string)$device['mac'];
                    }, $chain),
                    'hostname' => trim((string)$chain[0]['hostname']),
                    'first_seen' => min(array_map(function ($device) {
                        return (int)($device['first_seen'] ?? 0);
                    }, $chain)),
                ];
            }
        }

        return $groups;
    }

    /**
     * Whether $device could be the same machine as everything in $chain.
     */
    private static function fits(array $device, array $chain): bool
    {
        $name = self::chosenName($device);
        $shared = false;

        foreach ($chain as $member) {
            $other = self::chosenName($member);
            if ($name !== '' && $other !== '' && $name !== $other) {
                return false;
            }
            if (self::overlap($device, $member)) {
                return false;
            }
            $shared = $shared || array_intersect(self::segments($device), self::segments($member)) !== [];
        }

        return $shared;
    }

    /** two MACs held an address at the same time: two devices, whatever they are called */
    private static function overlap(array $left, array $right): bool
    {
        foreach ((array)($left['addresses'] ?? []) as $a) {
            foreach ((array)($right['addresses'] ?? []) as $b) {
                if (
                    (int)($a['first_seen'] ?? 0) <= (int)($b['last_seen'] ?? 0)
                    && (int)($b['first_seen'] ?? 0) <= (int)($a['last_seen'] ?? 0)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function segments(array $device): array
    {
        $out = [];
        foreach ((array)($device['addresses'] ?? []) as $window) {
            $out[(string)($window['interface'] ?? '')] = true;
        }

        return array_keys($out);
    }

    private static function chosenName(array $device): string
    {
        return trim((string)($device['label']['name'] ?? ''));
    }
}
