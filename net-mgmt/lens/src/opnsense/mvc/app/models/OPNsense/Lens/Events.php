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
 * Class Events
 *
 * What happened while nobody was looking (§4.63, BACKLOG #37): the kinds the
 * collector derives from its store, put on one time line, each a sentence with
 * the page that proves it. Rotation is told apart from a new device here,
 * because the fold (§4.61) lives in PHP. Pure.
 *
 * @package OPNsense\Lens
 */
class Events
{
    /** the filter chips: which kinds each one shows */
    public const GROUPS = [
        'new' => 'devices',
        'unusual' => 'devices',
        'outage' => 'internet',
        'gateway' => 'internet',
        'rotated' => 'identity',
        'overlap' => 'identity',
    ];

    /**
     * @param array $raw `lens events` decoded
     * @param array $rows DeviceReport's device rows, folded as the operator chose
     * @param array $segments interface to the operator's name for it
     * @param int $now
     * @return array
     */
    public static function describe(array $raw, array $rows, array $segments, int $now): array
    {
        $byMac = [];
        foreach ($rows as $row) {
            foreach ((array)($row['macs'] ?? [$row['mac']]) as $mac) {
                $byMac[$mac] = $row;
            }
        }

        $events = array_merge(
            self::appeared((array)($raw['new'] ?? []), $byMac, $segments),
            self::unusual((array)($raw['unusual'] ?? []), $byMac),
            self::line((array)($raw['outages'] ?? []), (array)($raw['gateways'] ?? [])),
            self::overlaps((array)($raw['overlaps'] ?? []), $byMac, $segments)
        );

        usort($events, function ($left, $right) {
            return $right['at'] <=> $left['at'] ?: strcmp($left['kind'], $right['kind']);
        });

        $counts = ['all' => 0, 'devices' => 0, 'internet' => 0, 'identity' => 0];
        $muted = 0;
        foreach ($events as $event) {
            if ($event['muted']) {
                $muted++;
                continue;
            }
            $counts['all']++;
            $counts[$event['group']]++;
        }

        $days = (int)($raw['days'] ?? 7);

        return [
            'days' => $days,
            'since' => (int)($raw['since'] ?? $now - $days * 86400),
            'now' => $now,
            'events' => $events,
            'counts' => $counts,
            'muted' => $muted,
            'headline' => self::headline($counts['all'], $muted, $days),
            'notes' => self::notes($raw, $now),
        ];
    }

    private static function headline(int $shown, int $muted, int $days): string
    {
        $range = $days === 1 ? gettext('the last 24 hours') : sprintf(gettext('the last %d days'), $days);

        if ($shown === 0) {
            $sentence = sprintf(gettext('Nothing worth telling you in %s.'), $range);
        } elseif ($shown === 1) {
            $sentence = sprintf(gettext('One thing happened in %s.'), $range);
        } else {
            $sentence = sprintf(gettext('%d things happened in %s.'), $shown, $range);
        }

        if ($muted > 0) {
            $sentence .= ' ' . sprintf(
                $muted === 1
                    ? gettext('One more from a device you muted.')
                    : gettext('%d more from devices you muted.'),
                $muted
            );
        }

        return $sentence;
    }

    /** what the list cannot contain, and why -- said, not left to be noticed */
    private static function notes(array $raw, int $now): array
    {
        $notes = [];
        $since = (int)($raw['since'] ?? $now);

        if (($raw['new_from'] ?? null) !== null && (int)$raw['new_from'] > $since) {
            $notes[] = gettext(
                'Devices seen in the first two days Lens watched are not called new: at first, every device is.'
            );
        }
        $needs = (int)($raw['baseline']['needs_days'] ?? 21);
        if (($raw['watching_since'] ?? null) !== null && (int)$raw['watching_since'] + $needs * 86400 > $since) {
            $notes[] = sprintf(
                gettext('A day is called unusual only once a device has %d days of history before it.'),
                $needs
            );
        }
        if (empty($raw['probing'])) {
            $notes[] = gettext(
                'Pinging the internet is switched off under Settings, so outages are known only from the gateway.'
            );
        }
        if (empty($raw['sampling'])) {
            $notes[] = gettext(
                'Gateway history is switched off under Settings, so the line\'s bad spells are not recorded.'
            );
        }

        return $notes;
    }

    private static function event(
        string $kind,
        int $at,
        string $tone,
        string $word,
        string $title,
        string $detail,
        ?array $row,
        string $link,
        array $extra = []
    ): array {
        return array_merge([
            'kind' => $kind,
            'group' => self::GROUPS[$kind],
            'at' => $at,
            /* an unusual day is a day, not a moment; the page writes its date only */
            'grain' => 'moment',
            'tone' => $tone,
            'word' => $word,
            'title' => $title,
            'detail' => $detail,
            'device' => $row === null ? null : [
                'mac' => $row['mac'],
                'macs' => array_values((array)($row['macs'] ?? [$row['mac']])),
                'name' => $row['name'],
                'muted' => !empty($row['muted']),
                'interfaces' => array_values((array)($row['interfaces'] ?? [])),
                'tags' => array_values((array)($row['tags'] ?? [])),
            ],
            'muted' => $row !== null && !empty($row['muted']),
            'link' => $link,
        ], $extra);
    }

    private static function page(?array $row, string $mac): string
    {
        return '/ui/lens/device?mac=' . rawurlencode($row['mac'] ?? $mac);
    }

    /**
     * New devices, and the rotations that only look like one: a folded row
     * that existed before this MAC appeared is the same phone with a new
     * private address (§4.61), not news.
     */
    private static function appeared(array $new, array $byMac, array $segments): array
    {
        $events = [];
        foreach ($new as $entry) {
            $mac = (string)($entry['mac'] ?? '');
            $at = (int)($entry['at'] ?? 0);
            $row = $byMac[$mac] ?? null;
            $name = $row['name'] ?? $mac;

            if ($row !== null && count((array)($row['macs'] ?? [])) > 1 && (int)$row['first_seen'] < $at) {
                $events[] = self::event(
                    'rotated',
                    $at,
                    'info',
                    gettext('Rotated'),
                    sprintf(gettext('%s switched to a new private address'), $name),
                    sprintf(
                        gettext('%s - the same device, %d private addresses by then. Phones do this on purpose.'),
                        $mac,
                        /* the row lists its MACs newest first */
                        count($row['macs']) - (int)array_search($mac, $row['macs'], true)
                    ),
                    $row,
                    self::page($row, $mac)
                );
                continue;
            }

            $interface = $row['interfaces'][0] ?? null;
            $where = $interface === null ? null : ($segments[$interface] ?? $interface);
            $events[] = self::event(
                'new',
                $at,
                'notice',
                gettext('New'),
                $where === null
                    ? sprintf(gettext('%s appeared'), $name)
                    : sprintf(gettext('%s joined %s'), $name, $where),
                implode(' - ', array_filter([
                    $row['vendor'] ?? null,
                    $mac,
                    $row !== null && !empty($row['randomised']) ? gettext('a private address') : null,
                ])),
                $row,
                self::page($row, $mac)
            );
        }

        return $events;
    }

    private static function unusual(array $days, array $byMac): array
    {
        $events = [];
        foreach ($days as $entry) {
            $mac = (string)($entry['mac'] ?? '');
            $row = $byMac[$mac] ?? null;
            $name = $row['name'] ?? $mac;
            $sent = ($entry['direction'] ?? 'total') === 'sent';
            $times = (float)($sent ? ($entry['sent_times'] ?? 0) : ($entry['times'] ?? 0));
            $times = rtrim(rtrim(number_format($times, 1), '0'), '.');
            $moved = Bytes::human((int)($sent ? ($entry['sent'] ?? 0) : ($entry['octets'] ?? 0)));
            $usual = Bytes::human((int)($sent ? ($entry['sent_usual'] ?? 0) : ($entry['usual'] ?? 0)));

            if ($sent) {
                $title = !empty($entry['partial'])
                    ? sprintf(gettext('%s has sent %s so far today'), $name, $moved)
                    : sprintf(gettext('%s sent %s'), $name, $moved);
                $detail = sprintf(gettext('%s times its usual upload of %s.'), $times, $usual);
            } else {
                $title = !empty($entry['partial'])
                    ? sprintf(gettext('%s has moved %s so far today'), $name, $moved)
                    : sprintf(gettext('%s moved %s'), $name, $moved);
                $detail = sprintf(gettext('%s times its usual day of %s; its uploads were ordinary.'), $times, $usual);
            }

            $events[] = self::event(
                'unusual',
                (int)($entry['day'] ?? 0),
                'notice',
                gettext('Unusual'),
                $title,
                $detail,
                $row,
                self::page($row, $mac),
                ['grain' => 'day']
            );
        }

        return $events;
    }

    /**
     * The internet and the line. A gateway that was down during an outage is
     * the reason for it, so it is said in the outage's sentence, not twice.
     */
    private static function line(array $outages, array $gateways): array
    {
        $events = [];
        $absorbed = [];

        foreach ($outages as $outage) {
            $from = (int)$outage['from'];
            $to = (int)$outage['to'];
            $with = [];
            foreach ($gateways as $index => $run) {
                if ($run['state'] === 'down' && (int)$run['from'] <= $to && (int)$run['to'] >= $from) {
                    $with[] = (string)$run['name'];
                    $absorbed[$index] = true;
                }
            }
            $for = Duration::span(max(0, $to - $from));
            $events[] = self::event(
                'outage',
                $from,
                'alert',
                gettext('Outage'),
                !empty($outage['ongoing'])
                    ? sprintf(gettext('The internet is unreachable, for %s so far'), $for)
                    : sprintf(gettext('The internet was unreachable for %s'), $for),
                $with === []
                    ? gettext('None of the resolvers Lens pings answered.')
                    : sprintf(gettext('%s was down with it.'), implode(', ', array_unique($with))),
                null,
                '/ui/lens/dashboard'
            );
        }

        foreach ($gateways as $index => $run) {
            if (isset($absorbed[$index])) {
                continue;
            }
            $for = Duration::span(max(0, (int)$run['to'] - (int)$run['from']));
            $down = $run['state'] === 'down';
            $loss = (float)($run['loss'] ?? 0);
            $events[] = self::event(
                'gateway',
                (int)$run['from'],
                $down ? 'alert' : 'notice',
                $down ? gettext('Line down') : gettext('Line'),
                $down
                    ? sprintf(gettext('%s was down for %s'), $run['name'], $for)
                    : sprintf(gettext('%s struggled for %s'), $run['name'], $for),
                $down ? gettext('Its monitor stopped answering.') : ($loss > 0
                    ? sprintf(gettext('Up to %s%% of packets lost.'), rtrim(rtrim(number_format($loss, 1), '0'), '.'))
                    : gettext('Its latency was above the threshold set on the gateway.')),
                null,
                '/ui/lens/dashboard',
                !empty($run['ongoing']) ? ['ongoing' => true] : []
            );
        }

        return $events;
    }

    private static function overlaps(array $overlaps, array $byMac, array $segments): array
    {
        $events = [];
        foreach ($overlaps as $overlap) {
            $macs = (array)($overlap['macs'] ?? []);
            $rows = array_map(function ($mac) use ($byMac) {
                return $byMac[$mac] ?? null;
            }, $macs);
            $names = [];
            foreach ($macs as $index => $mac) {
                $names[] = $rows[$index]['name'] ?? $mac;
            }
            $interface = (string)($overlap['interface'] ?? '');
            $first = $rows[0] ?? null;
            $event = self::event(
                'overlap',
                (int)($overlap['at'] ?? 0),
                'notice',
                gettext('Identity'),
                sprintf(
                    gettext('%s on %s was held by two devices at once'),
                    (string)($overlap['address'] ?? ''),
                    $segments[$interface] ?? $interface
                ),
                sprintf(
                    gettext('%s - Lens leaves that address\'s traffic unattributed rather than guess.'),
                    implode(gettext(' and '), $names)
                ),
                $first,
                self::page($first, (string)($macs[0] ?? ''))
            );
            /* news about two devices is muted only when both are */
            $event['muted'] = $rows !== [] && count(array_filter($rows, function ($row) {
                return $row !== null && !empty($row['muted']);
            })) === count($rows);
            $events[] = $event;
        }

        return $events;
    }
}
