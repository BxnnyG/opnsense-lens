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
use OPNsense\Lens\SourceFacts;
use OPNsense\Lens\SourceProbe;
use OPNsense\Lens\SourceReport;

/**
 * Class SourcesController
 *
 * Input and output only. Every reply is handed to SourceFacts, which assembles
 * facts, and on to SourceReport, which decides what they mean -- both of them
 * pure, both testable from recorded output without a router. Nothing in this
 * file interprets anything, because the one time it did, the interpretation had
 * no test and shipped wrong (SourceFacts, 2026-08-30).
 *
 * @package OPNsense\Lens\Api
 */
class SourcesController extends ApiControllerBase
{
    /** where core keeps the statistics database this page stats */
    private const UNBOUND_DB = '/var/unbound/data/unbound.duckdb';

    /**
     * What this box can actually tell Lens.
     *
     * Read-only configd calls plus two config reads and one stat. Nothing polls;
     * the page asks once when a human opens it. The timing is returned and shown,
     * because stage 1 cost two seconds and nobody could say which call spent them
     * (4.8).
     *
     * @return array
     */
    public function reportAction()
    {
        $started = microtime(true);
        $backend = new Backend();
        $timing = [];

        $run = function ($name) use ($backend, &$timing) {
            $command = SourceProbe::command($name);
            $at = microtime(true);
            $raw = (string)$backend->configdRun($command);
            $timing[$command] = (int)round((microtime(true) - $at) * 1000);
            return $raw;
        };

        $raw = $this->collect($run);

        return [
            'version' => SourceProbe::version(),
            'sources' => SourceReport::assess(SourceFacts::assemble($raw)),
            'retention' => SourceReport::retention(),
            'timing' => [
                'total_ms' => (int)round((microtime(true) - $started) * 1000),
                'calls' => $timing,
            ],
        ];
    }

    /**
     * @param callable $run runs one named configd command
     * @return array raw replies and file facts
     */
    private function collect(callable $run): array
    {
        $config = Config::getInstance()->object();
        $netflow = $config->xpath('//OPNsense/Netflow');
        $unbound = $config->xpath('//OPNsense/unboundplus/general');

        $raw = [
            'now' => time(),
            'interfaces' => self::interfaces($config),
            'netflow_capture' => empty($netflow) ? '' : (string)$netflow[0]->capture->interfaces,
            'netflow_collect' => empty($netflow) ? '' : (string)$netflow[0]->collect->enable,
            'unbound_stats' => empty($unbound) ? '' : (string)$unbound[0]->stats,
            'unbound_mtime' => is_file(self::UNBOUND_DB) ? filemtime(self::UNBOUND_DB) : null,
        ];

        foreach (['netflow_metadata', 'netflow_collector', 'netflow_aggregator', 'arp',
                  'dnsmasq_status', 'unbound_status'] as $name) {
            $raw[$name] = $run($name);
        }

        /* dnsmasq answers two questions -- who leases addresses and who resolves
           -- so it is asked once. Kea is only asked when dnsmasq is not there,
           which saves two calls on the common box; SourceFacts is correct either
           way, so this stays a saving and not a decision. */
        if (SourceProbe::serviceState($raw['dnsmasq_status']) === true) {
            $raw['dnsmasq_leases'] = $run('dnsmasq_leases');
        } else {
            $raw['kea_status'] = $run('kea_status');
            if (SourceProbe::serviceState($raw['kea_status']) === true) {
                $raw['kea_leases'] = $run('kea_leases');
            }
        }

        return $raw;
    }

    /**
     * @param \SimpleXMLElement $config
     * @return array list of ['key' => , 'if' => , 'descr' => ]
     */
    private static function interfaces($config): array
    {
        $interfaces = [];

        foreach ($config->interfaces->children() as $key => $interface) {
            $interfaces[] = [
                'key' => (string)$key,
                'if' => (string)$interface->if,
                'descr' => (string)$interface->descr,
            ];
        }

        return $interfaces;
    }
}
