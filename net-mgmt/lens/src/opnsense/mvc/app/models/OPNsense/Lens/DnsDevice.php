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
 * Class DnsDevice
 *
 * What one device looked up (§4.64), and exactly how much of it Lens could see:
 * Unbound answers the newest 500 queries per request, and the card says when
 * that cap was reached rather than presenting a sample as a total. Pure.
 *
 * @package OPNsense\Lens
 */
class DnsDevice
{
    public const SHOWN = 20;

    /**
     * @param array $raw `lens dns device` decoded
     * @param array $unbound ['enabled' => bool, 'stats' => bool]
     * @return array
     */
    public static function describe(array $raw, array $unbound): array
    {
        if (empty($raw['enabled'])) {
            return ['shown' => false, 'rows' => [], 'note' => gettext(
                'Switched off under Services: Lens: Settings.'
            ), 'settings' => true];
        }
        if (empty($unbound['enabled']) || empty($unbound['stats'])) {
            return ['shown' => false, 'rows' => [], 'note' => empty($unbound['enabled'])
                ? gettext('This firewall does not resolve with Unbound, and only Unbound records what was looked up.')
                : gettext('Unbound does not record queries here: Services: Unbound DNS: Statistics.')];
        }
        if ((int)($raw['asked'] ?? 0) === 0) {
            return ['shown' => false, 'rows' => [], 'note' => gettext('It held no address in this range.')];
        }

        $domains = array_slice((array)($raw['domains'] ?? []), 0, self::SHOWN);
        $largest = max(1, (int)($domains[0]['count'] ?? 1));
        $rows = array_map(function ($entry) use ($largest) {
            return [
                'domain' => (string)$entry['domain'],
                'count' => number_format((int)$entry['count']),
                'blocked' => (int)($entry['blocked'] ?? 0) > 0,
                'blocklist' => $entry['blocklist'] ?? null,
                'last' => (int)($entry['last'] ?? 0),
                'bar' => round((int)$entry['count'] / $largest * 100, 1),
            ];
        }, $domains);

        $store = ($raw['source'] ?? 'stats') === 'store';

        return [
            'shown' => $rows !== [],
            'rows' => $rows,
            /* its week of questions, from the store only: a sample would draw a wrong week (§4.70) */
            'heatmap' => $store && !empty($raw['heatmap']) ? Heatmap::grid((array)$raw['heatmap'], true) : null,
            'summary' => sprintf(
                gettext('%s questions seen, %s blocked, %d different names.'),
                number_format((int)($raw['queries'] ?? 0)),
                number_format((int)($raw['blocked'] ?? 0)),
                count((array)($raw['domains'] ?? []))
            ),
            'note' => $store
                ? gettext(
                    'Every question it asked, from Unbound\'s own record, in the hours it alone held its address.'
                )
                : self::note($raw),
        ];
    }

    /** what the numbers are a sample of, said every time it matters */
    private static function note(array $raw): string
    {
        $asked = (int)($raw['asked'] ?? 0);
        $capped = (int)($raw['capped_pieces'] ?? 0);
        $answered = (int)($raw['answered'] ?? 0);
        $parts = [];

        if ((int)($raw['queries'] ?? 0) === 0) {
            $parts[] = gettext(
                'Unbound recorded nothing from its addresses in this range - it may ask another resolver.'
            );
        }
        if ($capped > 0) {
            $parts[] = sprintf(
                gettext(
                    'Unbound answers at most 500 questions per request. %d of the %d stretches asked had more, '
                    . 'so these counts are the newest 500 of each, not all of them.'
                ),
                $capped,
                $asked
            );
        }
        if ($answered < $asked) {
            $parts[] = sprintf(gettext('%d of %d requests to Unbound did not answer.'), $asked - $answered, $asked);
        }

        return implode(' ', $parts);
    }
}
