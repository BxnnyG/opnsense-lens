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

namespace OPNsense\Lens\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Lens\SourceProbe;
use OPNsense\Lens\SourceReport;

/**
 * Class SourcesController
 *
 * Gathers raw facts and hands them to SourceReport, which decides what they
 * mean. Nothing here judges anything: keeping the judgement in a function with
 * no Backend and no filesystem is what makes every verdict testable without a
 * router (DESIGN 0).
 *
 * @package OPNsense\Lens\Api
 */
class SourcesController extends ApiControllerBase
{
    /** where core keeps the things this page stats */
    private const UNBOUND_DB = '/var/unbound/data/unbound.duckdb';

    /**
     * What this box can actually tell Lens.
     *
     * Seven read-only configd calls plus two config reads and one stat. Nothing
     * polls; the page asks once when a human opens it. The timing is returned
     * and shown, because stage 1 cost two seconds and nobody could say which
     * call spent them (4.8).
     *
     * @return array
     */
    public function reportAction()
    {
        $started = microtime(true);
        $backend = new Backend();
        $timing = [];

        $run = function ($command) use ($backend, &$timing) {
            $at = microtime(true);
            $raw = (string)$backend->configdRun($command);
            $timing[$command] = (int)round((microtime(true) - $at) * 1000);
            return $raw;
        };

        $facts = $this->gather($run);

        return [
            'version' => SourceProbe::version(),
            'sources' => SourceReport::assess($facts),
            'retention' => SourceReport::retention(),
            'timing' => [
                'total_ms' => (int)round((microtime(true) - $started) * 1000),
                'calls' => $timing,
            ],
        ];
    }

    /**
     * @param callable $run runs one configd command
     * @return array the fact set SourceReport::assess() consumes
     */
    private function gather(callable $run): array
    {
        return [
            'now' => time(),
            'netflow' => $this->netflowFacts($run),
            'arp' => ['entries' => SourceProbe::countOf($run(SourceProbe::command('arp')))],
            'dhcp' => $this->dhcpFacts($run),
            'dns' => $this->dnsFacts($run),
        ];
    }

    private function netflowFacts(callable $run): array
    {
        $config = Config::getInstance()->object();
        $node = $config->xpath('//OPNsense/Netflow');
        $captured = [];
        $collect = false;

        if (!empty($node)) {
            $captured = array_values(array_filter(
                explode(',', (string)$node[0]->capture->interfaces)
            ));
            $collect = (string)$node[0]->collect->enable === '1';
        }

        $metadata = json_decode($run(SourceProbe::command('netflow_metadata')), true);

        return [
            'interfaces' => self::interfaceNames($config),
            'capture_interfaces' => $captured,
            'collect_enabled' => $collect,
            'collector_running' => SourceProbe::serviceState($run(SourceProbe::command('netflow_collector'))),
            'aggregator_running' => SourceProbe::serviceState($run(SourceProbe::command('netflow_aggregator'))),
            'last_sync' => is_array($metadata) && isset($metadata['last_sync'])
                ? (int)$metadata['last_sync'] : null,
        ];
    }

    private function dhcpFacts(callable $run): array
    {
        if (SourceProbe::serviceState($run(SourceProbe::command('dnsmasq_status'))) === true) {
            return [
                'server' => 'dnsmasq',
                'leases' => SourceProbe::countOf($run(SourceProbe::command('dnsmasq_leases'))),
            ];
        }

        if (SourceProbe::serviceState($run(SourceProbe::command('kea_status'))) === true) {
            return [
                'server' => 'Kea',
                'leases' => SourceProbe::countOf($run(SourceProbe::command('kea_leases'))),
            ];
        }

        return ['server' => null, 'leases' => 0];
    }

    private function dnsFacts(callable $run): array
    {
        $node = Config::getInstance()->object()->xpath('//OPNsense/unboundplus/general');

        return [
            'stats_configured' => !empty($node) && (string)$node[0]->stats === '1',
            'running' => SourceProbe::serviceState($run(SourceProbe::command('unbound_status'))),
            'dnsmasq_running' => is_file('/var/db/dnsmasq.leases'),
            'data_mtime' => is_file(self::UNBOUND_DB) ? filemtime(self::UNBOUND_DB) : null,
        ];
    }

    /**
     * Interface key to the name a human uses. Loopback and interface groups --
     * which point at their own key rather than a device -- are not places
     * traffic can be captured, so they are left out of the coverage question.
     *
     * @param \SimpleXMLElement $config
     * @return array
     */
    private static function interfaceNames($config): array
    {
        $names = [];

        foreach ($config->interfaces->children() as $key => $interface) {
            $device = (string)$interface->if;
            if ($device === '' || $device === 'lo0' || $device === (string)$key) {
                continue;
            }
            $description = (string)$interface->descr;
            $names[(string)$key] = $description !== '' ? $description : strtoupper((string)$key);
        }

        return $names;
    }
}
