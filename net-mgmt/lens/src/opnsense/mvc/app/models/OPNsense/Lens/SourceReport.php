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
 * What the facts about a data source mean.
 *
 * A pure function from gathered facts to verdicts: no Backend, no filesystem,
 * no configuration. Everything it decides is therefore provable against a
 * recorded fixture without a router, and it is decided in exactly one place, so
 * the next surface to show a source cannot decide it differently (DESIGN 0).
 *
 * Three verdicts, never two. "degraded" is the whole point: a source that is
 * present but not usable is the common case in the wild and the one stage 1
 * could not express. On the operator's own box, Unbound is configured, running,
 * and answers -- and resolves nothing, because dnsmasq does. Stage 1 called that
 * green (DESIGN 4.18).
 *
 * @package OPNsense\Lens
 */
class SourceReport
{
    public const READY = 'ready';
    public const DEGRADED = 'degraded';
    public const ABSENT = 'absent';

    /** flowd_aggregate runs on a short cycle; quiet for longer means stuck */
    public const AGGREGATION_STALE_AFTER = 900;

    /** a resolver that has recorded nothing for an hour is not the resolver */
    public const DNS_STALE_AFTER = 3600;

    /**
     * @param array $facts as gathered by the controller
     * @return array one entry per source, in reading order
     */
    public static function assess(array $facts): array
    {
        $now = (int)($facts['now'] ?? time());

        return [
            self::netflow(($facts['netflow'] ?? []), $now),
            self::identity($facts['arp'] ?? []),
            self::dhcp($facts['dhcp'] ?? []),
            self::dns(($facts['dns'] ?? []), $now),
        ];
    }

    /**
     * Traffic history. The one source with a coverage question: it can be fully
     * configured, running and fresh, and still be blind to most of the network.
     */
    private static function netflow(array $f, int $now): array
    {
        $captured = $f['capture_interfaces'] ?? [];
        $known = $f['interfaces'] ?? [];
        $missing = array_diff(array_keys($known), $captured);

        $out = [
            'id' => 'netflow',
            'label' => gettext('Traffic history (NetFlow)'),
            'enables' => gettext('Traffic over time, per interface and per device'),
            'captured' => array_values(array_intersect_key($known, array_flip($captured))),
            'missing' => array_values(array_intersect_key($known, array_flip($missing))),
        ];

        if (empty($captured)) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('No interface is being captured'),
                'cause' => gettext('NetFlow has no listening interfaces, so no flow record is ever created.'),
                'action' => gettext(
                    'Reporting: NetFlow - choose the interfaces to listen on, set your WAN under "WAN interfaces", ' .
                    'and tick "Capture local".'
                ),
            ];
        }

        if (empty($f['collect_enabled'])) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('Flows are captured but nothing is kept'),
                'cause' => gettext('"Capture local" is off, so flow records are not stored on this firewall.'),
                'action' => gettext('Reporting: NetFlow - tick "Capture local".'),
            ];
        }

        if ($f['collector_running'] === false) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('The flow collector is not running'),
                'cause' => gettext('flowd is stopped, so flow records are being discarded as they arrive.'),
                'action' => gettext('Reporting: NetFlow - apply the settings again, which restarts the collector.'),
            ];
        }

        if ($f['aggregator_running'] === false) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('Flows are collected but not aggregated'),
                'cause' => gettext(
                    'flowd_aggregate is stopped. The raw log keeps growing and rotating, and everything it drops ' .
                    'before aggregation is gone for good.'
                ),
                'action' => gettext('Reporting: NetFlow - apply the settings again.'),
            ];
        }

        $age = isset($f['last_sync']) ? max(0, $now - (int)$f['last_sync']) : null;

        if ($age === null || $age > self::AGGREGATION_STALE_AFTER) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('Aggregation has stalled'),
                'cause' => $age === null
                    ? gettext('Aggregation has never run, or does not report when it last did.')
                    : sprintf(gettext(
                        'The last aggregation was %d minutes ago; it normally runs within a few.'
                    ), intdiv($age, 60)),
                'action' => gettext(
                    'Reporting: NetFlow - restart the aggregator, or repair the database if it keeps stalling.'
                ),
            ];
        }

        if (!empty($missing)) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => sprintf(
                    gettext('Capturing %d of %d interfaces'),
                    count($captured),
                    count($known)
                ),
                'cause' => sprintf(
                    gettext(
                        'Devices on %s produce no traffic history at all, and nothing else on this page will say ' .
                        'so.'
                    ),
                    implode(', ', $out['missing'])
                ),
                'action' => gettext(
                    'Reporting: NetFlow - add them under "Listening interfaces", or ignore this if leaving them out ' .
                    'is deliberate.'
                ),
            ];
        }

        return $out + [
            'verdict' => self::READY,
            'headline' => sprintf(gettext('Capturing all %d interfaces'), count($captured)),
            'cause' => sprintf(gettext('Aggregated %d seconds ago.'), $age),
            'action' => '',
        ];
    }

    /**
     * Who is on the network. The one source that needs no configuration.
     */
    private static function identity(array $f): array
    {
        $out = [
            'id' => 'identity',
            'label' => gettext('Device identity (ARP and NDP)'),
            'enables' => gettext('Which MAC address holds which IP address, and on which segment'),
        ];

        $entries = $f['entries'] ?? null;

        if ($entries === null) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('The address table could not be read'),
                'cause' => gettext('configd did not return an ARP table.'),
                'action' => gettext('Check that configd is running.'),
            ];
        }

        if ($entries === 0) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('The address table is empty'),
                'cause' => gettext('No neighbour has been seen. On a live network this should never be zero.'),
                'action' => '',
            ];
        }

        return $out + [
            'verdict' => self::READY,
            'headline' => sprintf(gettext('%d addresses in the table right now'), $entries),
            'cause' => gettext('This is a snapshot. Keeping it over time is what Lens adds; nothing in OPNsense does.'),
            'action' => '',
        ];
    }

    /**
     * Where hostnames come from. Without it a device is a MAC and a vendor.
     */
    private static function dhcp(array $f): array
    {
        $out = [
            'id' => 'dhcp',
            'label' => gettext('DHCP leases'),
            'enables' => gettext('Device hostnames, where a device announces one'),
        ];

        $server = $f['server'] ?? null;

        if ($server === null) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('No DHCP server is running here'),
                'cause' => gettext(
                    'Without leases, devices can only be recognised by MAC address and hardware vendor.'
                ),
                'action' => gettext(
                    'If another box hands out addresses, Lens cannot see its leases and names have to be set by ' .
                    'hand.'
                ),
            ];
        }

        $leases = (int)($f['leases'] ?? 0);

        if ($leases === 0) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => sprintf(gettext('%s is running but has no leases'), $server),
                'cause' => gettext(
                    'Either nothing has asked for an address yet, or every device here is statically configured.'
                ),
                'action' => '',
            ];
        }

        return $out + [
            'verdict' => self::READY,
            'headline' => sprintf(gettext('%d leases from %s'), $leases, $server),
            'cause' => gettext('Statically configured hosts never appear here and will need naming by hand.'),
            'action' => '',
        ];
    }

    /**
     * The source that taught this file its shape. Configured is not running,
     * and running is not serving (DESIGN 4.18).
     */
    private static function dns(array $f, int $now): array
    {
        $out = [
            'id' => 'dns',
            'label' => gettext('DNS query statistics (Unbound)'),
            'enables' => gettext('What each device looks up, and what was blocked'),
        ];

        if (empty($f['stats_configured'])) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('Unbound statistics are switched off'),
                'cause' => gettext('Nothing is recording queries, so there is nothing to show per device.'),
                'action' => gettext(
                    'Services: Unbound DNS: Statistics - tick "Enabled". Note that this records every name every ' .
                    'device looks up.'
                ),
            ];
        }

        if ($f['running'] === false) {
            return $out + [
                'verdict' => self::ABSENT,
                'headline' => gettext('Unbound is configured but not running'),
                'cause' => gettext('Statistics are switched on for a resolver that is stopped.'),
                'action' => gettext(
                    'Services: Unbound DNS - start it, or switch the statistics off so this page stops promising a ' .
                    'view you cannot have.'
                ),
            ];
        }

        $age = isset($f['data_mtime']) ? max(0, $now - (int)$f['data_mtime']) : null;

        if ($age === null) {
            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('No statistics database exists yet'),
                'cause' => gettext('Unbound is running with statistics on, but has not written anything.'),
                'action' => gettext('If this persists, Unbound is not the resolver these clients use.'),
            ];
        }

        if ($age > self::DNS_STALE_AFTER) {
            $cause = sprintf(
                gettext('Unbound is running, but has not recorded a query for %d hours.'),
                intdiv($age, 3600)
            );
            if (!empty($f['dnsmasq_running'])) {
                $cause .= ' ' . gettext(
                    'dnsmasq is running on this box and is what clients are actually resolving with, so Unbound ' .
                    'sees nothing.'
                );
            }

            return $out + [
                'verdict' => self::DEGRADED,
                'headline' => gettext('Running, but not the resolver'),
                'cause' => $cause,
                'action' => gettext(
                    'Either point clients at Unbound, or accept that the DNS view stays unavailable on this box.'
                ),
            ];
        }

        return $out + [
            'verdict' => self::READY,
            'headline' => gettext('Recording queries'),
            'cause' => sprintf(gettext('Last written %d seconds ago.'), $age),
            'action' => '',
        ];
    }

    /**
     * What core keeps, and for how long. Fixed in the aggregate classes, not a
     * setting -- so it can be stated without measuring anything (DESIGN 1.4).
     *
     * @return array rows for the retention table
     */
    public static function retention(): array
    {
        return [
            [
                'what' => gettext('Traffic per interface'),
                'detail' => gettext('30s for a day, 5min for a week, hourly for a month, daily for a year'),
            ],
            [
                'what' => gettext('Traffic per device'),
                'detail' => gettext('5min for ONE HOUR, hourly for ONE DAY, then daily for a year'),
            ],
            [
                'what' => gettext('Per device: who it talked to, on which port'),
                'detail' => gettext('daily only, for 62 days - there is no hourly version, ever'),
            ],
        ];
    }
}
