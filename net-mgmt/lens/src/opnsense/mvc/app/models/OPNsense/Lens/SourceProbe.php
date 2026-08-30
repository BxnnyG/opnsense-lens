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
 * Which of the data sources Lens reads are actually answering on this box.
 *
 * This is the liveness half of S3. The honest, three-level check -- configured,
 * running, and producing fresh data (DESIGN.md 4.18) -- is stage 2. Here a
 * source either answers or it does not, which is enough to prove the plugin can
 * reach the rest of OPNsense at all.
 *
 * Every command below is verified against opnsense/core stable/26.7 on
 * 2026-08-30. Note the spaces: configd addresses a dotted action name such as
 * [aggregate.metadata] as "netflow aggregate metadata" (DESIGN.md 1.8). Getting
 * that wrong answers "Action not allowed or missing", which reads like a
 * permission problem and is not.
 *
 * @package OPNsense\Lens
 */
class SourceProbe
{
    /** where the package drops its own version, as JSON */
    public const VERSION_FILE = '/usr/local/opnsense/version/lens';

    /** @var callable runs one configd command and returns its raw output */
    private $runner;

    public function __construct(callable $runner)
    {
        $this->runner = $runner;
    }

    /**
     * The sources, and what each one makes possible. The second half matters:
     * a missing source has to be explainable in one sentence, not as a red dot.
     */
    public static function probes(): array
    {
        return [
            [
                'id' => 'netflow',
                'label' => gettext('NetFlow aggregation'),
                'command' => 'netflow aggregate metadata json',
                'enables' => gettext('Traffic history, per interface and per device'),
            ],
            [
                'id' => 'arp',
                'label' => gettext('ARP table'),
                'command' => 'interface list arp json',
                'enables' => gettext('Device identity: which MAC holds which address'),
            ],
            [
                'id' => 'leases_dnsmasq',
                'label' => gettext('DHCP leases (dnsmasq)'),
                'command' => 'dnsmasq list leases',
                'enables' => gettext('Device hostnames, where a device announces one'),
            ],
            [
                'id' => 'leases_kea',
                'label' => gettext('DHCP leases (Kea)'),
                'command' => 'kea list leases4',
                'enables' => gettext('Device hostnames, where a device announces one'),
            ],
            [
                'id' => 'dns_unbound',
                'label' => gettext('DNS query statistics (Unbound)'),
                'command' => 'unbound qstats totals 1',
                'enables' => gettext('The DNS view. Absent on a box that resolves with dnsmasq.'),
            ],
        ];
    }

    /**
     * @return array the plugin version and one entry per source
     */
    public function report(): array
    {
        $sources = [];
        foreach (self::probes() as $probe) {
            $sources[] = array_merge($probe, self::interpret(
                (string)call_user_func($this->runner, $probe['command'])
            ));
        }

        return [
            'version' => self::version(),
            'sources' => $sources,
        ];
    }

    /**
     * What a configd answer means. Separate from the call so it can be tested
     * against recorded output without a router.
     *
     * @param string $raw configd output
     * @return array answered flag and a short human detail
     */
    public static function interpret(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['answered' => false, 'detail' => gettext('no answer')];
        }

        if (stripos($raw, 'Action not allowed or missing') !== false) {
            return ['answered' => false, 'detail' => gettext('configd does not know this action')];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            /* a script that failed prints its complaint in plain text */
            return ['answered' => false, 'detail' => gettext('answered, but not with data')];
        }

        return ['answered' => true, 'detail' => self::summarise($decoded)];
    }

    /**
     * One short line about what came back. Never the payload itself: it can be
     * long, and parts of it are strings supplied by devices on the LAN.
     *
     * @param mixed $decoded
     * @return string
     */
    private static function summarise($decoded): string
    {
        if (!is_array($decoded)) {
            return gettext('answered');
        }

        if (isset($decoded['records']) && is_array($decoded['records'])) {
            return sprintf(gettext('%d records'), count($decoded['records']));
        }

        if (isset($decoded['last_sync'])) {
            $age = time() - (int)$decoded['last_sync'];
            return sprintf(gettext('last aggregated %d seconds ago'), max(0, $age));
        }

        if (array_values($decoded) === $decoded) {
            return sprintf(gettext('%d entries'), count($decoded));
        }

        return gettext('answered');
    }

    /**
     * @param string $path version file, injectable for tests
     * @return string the installed package version, or an empty string
     */
    public static function version(string $path = self::VERSION_FILE): string
    {
        if (!is_readable($path)) {
            return '';
        }

        $decoded = json_decode((string)@file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['product_version'])) {
            return '';
        }

        return (string)$decoded['product_version'];
    }
}
