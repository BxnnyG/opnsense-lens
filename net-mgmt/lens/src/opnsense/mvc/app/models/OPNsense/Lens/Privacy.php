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
 * Class Privacy
 *
 * What Lens keeps about the people on the network, what it reads and does not
 * keep, and what forgetting one device did (§4.72). Every figure comes from the
 * store; every statement about core's copies was read in core's source on the
 * branch the box runs (stable/26.7), not assumed.
 *
 * @package OPNsense\Lens
 */
class Privacy
{
    /**
     * @param array $kept `lens kept` decoded
     * @param int $now
     * @return array
     */
    public static function describe(array $kept, int $now): array
    {
        $retention = (int)($kept['retention_days'] ?? 0);
        $keptFor = $retention > 0 ? sprintf(gettext('%d days'), $retention) : '';
        $kinds = [
            ['devices', gettext('Devices'),
                gettext('Each MAC address seen, when first and last, and the hostname it gave.'),
                gettext('to know a device again after its address changes')],
            ['windows', gettext('Address windows'),
                gettext('Which address a MAC held, on which network, from when to when.'),
                gettext('to put each hour of traffic on the device that held the address then')],
            ['traffic_hours', gettext('Traffic per hour'),
                gettext('Bytes and packets per address and direction, per hour.'),
                gettext('every chart and figure of traffic')],
            ['summed_days', gettext('Summed days'),
                gettext('Each device\'s bytes per complete day, summed from the hours above.'),
                gettext('the baseline and Events, without re-reading every hour')],
            ['destination_days', gettext('Destinations per day'),
                gettext('Which outside address and port each address talked to, per day.'),
                gettext('"where it talks" on a device\'s page')],
            ['labels', gettext('Your notes'),
                gettext('Names, types, tags, notes, mutes and owners you gave devices.'),
                gettext('what you wrote, shown back to you')],
            ['pauses', gettext('Pauses'),
                gettext('Which device you paused, from when, until when, and how it ended.'),
                gettext('the pause on a device\'s page, and Events')],
            ['line_samples', gettext('Line samples'),
                gettext('Gateway quality and round trips to public resolvers.'),
                gettext('the internet panel; describes the line, not a person')],
        ];

        $rows = [];
        foreach ($kinds as [$key, $what, $holds, $why]) {
            $entry = (array)($kept[$key] ?? []);
            $count = (int)($entry['rows'] ?? 0);
            $oldest = isset($entry['oldest']) ? (int)$entry['oldest'] : null;
            $off = $key === 'destination_days' && empty($kept['destinations_on']);
            $rows[] = [
                'key' => $key,
                'what' => $what,
                'holds' => $holds,
                'why' => $why,
                'rows' => $count,
                'oldest' => $count > 0 && $oldest !== null ? Duration::ago($now - $oldest) : '',
                /* a note stays until it is cleared or its device goes (§4.58) */
                'kept_for' => $key === 'labels' ? gettext('until you clear it, or its device goes')
                    /* a pause still running is the firewall's state, not history (§4.74) */
                    : ($key === 'pauses' ? sprintf(gettext('%s after it ends'), $keptFor) : $keptFor),
                'state' => $off ? ($count > 0 ? gettext('switched off; what was kept ages out')
                    : gettext('switched off')) : '',
            ];
        }

        $owners = (int)($kept['owners']['rows'] ?? 0);

        return [
            'kinds' => $rows,
            'retention_days' => $retention,
            'owners' => $owners,
            'headline' => $owners > 0
                ? sprintf(
                    gettext('Lens keeps %s of history about %d devices, %d of them named after a person.'),
                    $keptFor,
                    (int)($kept['devices']['rows'] ?? 0),
                    $owners
                )
                : sprintf(
                    gettext('Lens keeps %s of history about %d devices.'),
                    $keptFor,
                    (int)($kept['devices']['rows'] ?? 0)
                ),
            'elsewhere' => self::elsewhere(),
        ];
    }

    /**
     * What Lens reads and does not keep: core's own copies, with where they are
     * kept and cleared. Lens is read-only there (rule 6); forgetting a device
     * here does not touch them.
     */
    private static function elsewhere(): array
    {
        return [
            [
                'what' => gettext('DNS questions'),
                'where' => gettext('Unbound\'s query log, kept 7 days by core'),
                'clear' => gettext('Reporting: Unbound DNS, reset (all clients at once)'),
                'url' => '/ui/unbound/overview',
            ],
            [
                'what' => gettext('Traffic aggregates'),
                'where' => gettext(
                    'core\'s NetFlow data in /var/netflow: totals per address for a year, detail for 62 days'
                ),
                'clear' => gettext('Reporting: NetFlow, Reset Netflow Data (everything)'),
                'url' => '/ui/diagnostics/netflow',
            ],
            [
                'what' => gettext('Addresses and hostnames now'),
                'where' => gettext('the ARP and NDP tables and the DHCP leases: core\'s live state, not a history'),
                'clear' => '',
                'url' => '',
            ],
        ];
    }

    /**
     * @param array $answer `lens forget.device` or `lens forget.preview` decoded
     * @return array the counts in words, and whether anything was there
     */
    public static function forgotten(array $answer): array
    {
        $counts = (array)($answer['counts'] ?? []);
        $words = [
            'devices' => gettext('its record'),
            'windows' => gettext('%d address windows'),
            'traffic_hours' => gettext('%d hours of traffic'),
            'destination_days' => gettext('%d destination rows'),
            'summed_days' => gettext('%d summed days'),
            'labels' => gettext('your note'),
            'pauses' => gettext('%d past pauses'),
        ];
        $parts = [];
        foreach ($words as $key => $format) {
            $count = (int)($counts[$key] ?? 0);
            if ($count > 0) {
                $parts[] = in_array($key, ['devices', 'labels'], true) ? $format : sprintf($format, $count);
            }
        }
        $found = (int)($counts['devices'] ?? 0) > 0 || (int)($counts['windows'] ?? 0) > 0;
        $dry = !empty($answer['dry']);

        if (!isset($answer['counts'])) {
            return ['ok' => false, 'found' => false, 'dry' => $dry, 'parts' => [],
                    'sentence' => gettext('Lens did not answer.')];
        }

        if (!empty($counts['paused'])) {
            /* forgetting it would leave it blocked with nothing in Lens to say so */
            return ['ok' => false, 'found' => $found, 'dry' => $dry, 'parts' => $parts, 'paused' => true,
                    'sentence' => gettext('This device is paused. Resume it on its page first, then forget it.')];
        }

        if (!$found) {
            $sentence = gettext('Lens holds nothing about this device.');
        } elseif ($dry) {
            $sentence = sprintf(gettext('Forgetting it deletes %s.'), implode(', ', $parts));
        } else {
            $sentence = sprintf(
                gettext(
                    'Deleted %s. If the device is still on the network, Lens sees it again at the next observation.'
                ),
                implode(', ', $parts)
            );
        }

        return ['ok' => true, 'found' => $found, 'dry' => $dry, 'parts' => $parts, 'sentence' => $sentence];
    }
}
