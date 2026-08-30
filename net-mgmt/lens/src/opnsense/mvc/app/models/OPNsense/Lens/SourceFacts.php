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
 * Raw replies in, facts out.
 *
 * This class exists because of a bug shipped on 2026-08-30. SourceReport was
 * fully tested and correct; the controller that fed it silently never set the
 * key it read, so the page printed the wrong verdict while every test passed.
 * The fault was not in either layer but in the wiring between them -- and the
 * wiring was the one part with no test, because it was tangled up with Backend
 * and Config calls.
 *
 * So the assembly moved here, where it takes strings and returns arrays and can
 * be exercised from recorded configd output all the way to a verdict. The
 * controller is left with nothing but input and output.
 *
 * @package OPNsense\Lens
 */
class SourceFacts
{
    /**
     * @param array $raw configd replies and file facts, keyed by name
     * @return array the fact set SourceReport::assess() consumes
     */
    public static function assemble(array $raw): array
    {
        $dnsmasqRunning = SourceProbe::serviceState((string)($raw['dnsmasq_status'] ?? ''));

        return [
            'now' => (int)($raw['now'] ?? time()),
            'netflow' => self::netflow($raw),
            'arp' => ['entries' => SourceProbe::countOf((string)($raw['arp'] ?? ''))],
            'dhcp' => self::dhcp($raw, $dnsmasqRunning),
            'dns' => self::dns($raw, $dnsmasqRunning),
        ];
    }

    private static function netflow(array $raw): array
    {
        $metadata = json_decode((string)($raw['netflow_metadata'] ?? ''), true);

        return [
            'interfaces' => self::interfaceNames($raw['interfaces'] ?? []),
            'capture_interfaces' => array_values(array_filter(
                explode(',', (string)($raw['netflow_capture'] ?? ''))
            )),
            'collect_enabled' => (string)($raw['netflow_collect'] ?? '') === '1',
            'collector_running' => SourceProbe::serviceState((string)($raw['netflow_collector'] ?? '')),
            'aggregator_running' => SourceProbe::serviceState((string)($raw['netflow_aggregator'] ?? '')),
            'last_sync' => is_array($metadata) && isset($metadata['last_sync'])
                ? (int)$metadata['last_sync'] : null,
        ];
    }

    private static function dhcp(array $raw, ?bool $dnsmasqRunning): array
    {
        if ($dnsmasqRunning === true) {
            return [
                'server' => 'dnsmasq',
                'leases' => SourceProbe::countOf((string)($raw['dnsmasq_leases'] ?? '')),
            ];
        }

        if (SourceProbe::serviceState((string)($raw['kea_status'] ?? '')) === true) {
            return [
                'server' => 'Kea',
                'leases' => SourceProbe::countOf((string)($raw['kea_leases'] ?? '')),
            ];
        }

        /* OPNsense ships three DHCP servers and a box runs whichever it runs.
           Knowing only two of them made the page report "no DHCP server is
           running here" on a firewall that was leasing every address on the
           network -- an absence asserted from an incomplete list. */
        if (SourceProbe::serviceState((string)($raw['dhcpd_status'] ?? '')) === true) {
            return [
                'server' => 'ISC DHCP',
                'leases' => SourceProbe::countOf((string)($raw['dhcpd_leases'] ?? '')),
            ];
        }

        return ['server' => null, 'leases' => 0];
    }

    private static function dns(array $raw, ?bool $dnsmasqRunning): array
    {
        return [
            'stats_configured' => (string)($raw['unbound_stats'] ?? '') === '1',
            'running' => SourceProbe::serviceState((string)($raw['unbound_status'] ?? '')),
            /* whether anything else is resolving decides choice from fault (4.20) */
            'other_resolver' => $dnsmasqRunning === true ? 'dnsmasq' : null,
            'data_mtime' => isset($raw['unbound_mtime']) ? (int)$raw['unbound_mtime'] : null,
        ];
    }

    /**
     * Interface key to the name a human uses.
     *
     * Loopback and interface groups -- which name themselves as their own
     * device rather than a real one -- are not places traffic can be captured,
     * so they are left out of the coverage question entirely. Nobody thinks in
     * "opt3" either, so the description wins where there is one.
     *
     * @param array $interfaces list of ['key' => , 'if' => , 'descr' => ]
     * @return array key to display name
     */
    public static function interfaceNames(array $interfaces): array
    {
        $names = [];

        foreach ($interfaces as $interface) {
            $key = (string)($interface['key'] ?? '');
            $device = (string)($interface['if'] ?? '');

            if ($key === '' || $device === '' || $device === 'lo0' || $device === $key) {
                continue;
            }

            $description = (string)($interface['descr'] ?? '');
            $names[$key] = $description !== '' ? $description : strtoupper($key);
        }

        return $names;
    }
}
