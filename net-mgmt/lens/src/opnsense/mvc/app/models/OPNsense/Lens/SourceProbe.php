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
 * How Lens talks to configd, and how it reads the answers.
 *
 * Nothing here judges a source -- that is SourceReport. This is the boundary
 * layer: the exact commands, and the three ways their replies are read. All of
 * it is static and pure, so the boundary is testable without a router, which
 * matters because every plugin bug in this ecosystem's history is a boundary
 * bug.
 *
 * Every command is verified against opnsense/core stable/26.7 on 2026-08-30.
 * Note the spaces: configd addresses a dotted action name such as
 * [aggregate.metadata] as "netflow aggregate metadata" (DESIGN 1.8). Writing
 * the dot answers "Action not allowed or missing", which reads like a
 * permission problem and is not -- four of the first tool's commands were lost
 * to exactly that.
 *
 * @package OPNsense\Lens
 */
class SourceProbe
{
    /** where the package drops its own version, as JSON */
    public const VERSION_FILE = '/usr/local/opnsense/version/lens';

    /**
     * @return array command name to configd command line
     */
    public static function commands(): array
    {
        return [
            'netflow_metadata' => 'netflow aggregate metadata json',
            'netflow_collector' => 'netflow collect status',
            'netflow_aggregator' => 'netflow aggregate status',
            'arp' => 'interface list arp json',
            'dnsmasq_status' => 'dnsmasq status',
            'dnsmasq_leases' => 'dnsmasq list leases',
            'kea_status' => 'kea status',
            'kea_leases' => 'kea list leases4',
            'unbound_status' => 'unbound status',
        ];
    }

    /**
     * @param string $name key from commands()
     * @return string the configd command line
     */
    public static function command(string $name): string
    {
        $commands = self::commands();

        if (!isset($commands[$name])) {
            throw new \InvalidArgumentException('unknown configd command: ' . $name);
        }

        return $commands[$name];
    }

    /**
     * Whether a daemon is up, from an rc script's own words.
     *
     * "not running" is tested before "is running" on purpose: the negative form
     * contains the positive one. An answer in neither form is null -- unknown,
     * never healthy. Freshness of the data outranks this anyway; a source whose
     * output is current is working whatever a status string says.
     *
     * @param string $raw configd output of a status action
     * @return bool|null true, false, or null when the reply says neither
     */
    public static function serviceState(string $raw): ?bool
    {
        $raw = strtolower(trim($raw));

        if ($raw === '') {
            return null;
        }

        if (strpos($raw, 'not running') !== false || strpos($raw, 'is stopped') !== false) {
            return false;
        }

        if (strpos($raw, 'is running') !== false) {
            return true;
        }

        return null;
    }

    /**
     * How many things came back, whether the reply wraps them in "records" or
     * is a bare list.
     *
     * Null means the call did not answer with data at all, which is a different
     * thing from answering with none -- a DHCP server with no leases is working,
     * a missing one is not, and the page must not print the same word for both.
     *
     * @param string $raw configd output
     * @return int|null
     */
    public static function countOf(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || stripos($raw, 'Action not allowed or missing') !== false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            /* a backing script that failed prints its complaint, not JSON */
            return null;
        }

        if (isset($decoded['records']) && is_array($decoded['records'])) {
            return count($decoded['records']);
        }

        return count($decoded);
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
