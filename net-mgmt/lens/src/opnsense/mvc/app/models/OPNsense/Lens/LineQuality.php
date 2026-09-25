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
 * Class LineQuality
 *
 * Is the internet all right, and if not, which line and how. Pure.
 *
 * Whether a gateway counts as slow is **core's** judgement, not Lens's: dpinger
 * compares every measurement against the latency and loss thresholds the
 * operator configured on that gateway, and reports `delay`, `loss` or `down`.
 * Lens inventing its own thresholds would mean the dashboard calling a line
 * slow that System: Gateways calls fine, and one of them would be wrong (§4.56).
 *
 * @package OPNsense\Lens
 */
class LineQuality
{
    /**
     * @param array $live `interface gateways status` decoded
     * @param array $history `lens gateways` decoded, may be empty
     * @return array
     */
    public static function describe(array $live, array $history): array
    {
        $series = (array)($history['gateways'] ?? []);
        $lines = [];

        foreach ($live as $name => $gateway) {
            if (!is_array($gateway)) {
                continue;
            }

            $status = strtolower((string)($gateway['status'] ?? ''));
            $lines[] = [
                'name' => (string)($gateway['name'] ?? $name),
                'monitor' => self::shown($gateway['monitor'] ?? ''),
                'state' => self::state($status),
                'status' => (string)($gateway['status_translated'] ?? $status),
                'delay' => self::number($gateway['delay'] ?? null),
                'stddev' => self::number($gateway['stddev'] ?? null),
                'loss' => self::number($gateway['loss'] ?? null),
                'monitored' => self::number($gateway['delay'] ?? null) !== null,
                'series' => array_values((array)($series[$gateway['name'] ?? $name] ?? [])),
            ];
        }

        /* the worst line first: it is the one the question was about */
        $rank = ['down' => 0, 'degraded' => 1, 'pending' => 2, 'good' => 3];
        usort($lines, function ($left, $right) use ($rank) {
            return $rank[$left['state']] <=> $rank[$right['state']] ?: strcmp($left['name'], $right['name']);
        });

        return [
            'lines' => $lines,
            'worst' => $lines[0]['state'] ?? null,
            'step' => (int)($history['step'] ?? 3600),
        ];
    }

    private static function state(string $status): string
    {
        switch ($status) {
            case 'none':
                return 'good';
            case 'delay':
            case 'loss':
            case 'delay+loss':
                return 'degraded';
            case 'down':
            case 'force_down':
                return 'down';
            default:
                return 'pending';
        }
    }

    /** "12.3 ms" -> 12.3; "~" means dpinger is not measuring, which is null, not 0 */
    private static function number($value): ?float
    {
        $first = explode(' ', trim((string)$value))[0];

        return is_numeric($first) ? (float)$first : null;
    }

    private static function shown($value): ?string
    {
        $value = trim((string)$value);

        return $value === '' || $value === '~' ? null : $value;
    }
}
