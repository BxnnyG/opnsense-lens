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
 * Class SystemDetail
 *
 * What stands behind each tile of the health row, as lists for the System
 * page (stage 53): the tile is the question, this is the answer, in Lens.
 * Read from the same sources as Health; pure.
 *
 * @package OPNsense\Lens
 */
class SystemDetail
{
    /** every service core lists: the stopped ones first, then by name */
    public static function services(array $services): array
    {
        $out = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $checked = empty($service['nocheck']) && empty($service['locked']);
            $out[] = [
                'name' => (string)($service['name'] ?? ''),
                'description' => (string)($service['description'] ?? $service['name'] ?? ''),
                'running' => strpos((string)($service['status'] ?? ''), 'is running') !== false,
                'checked' => $checked,
            ];
        }
        usort($out, function ($left, $right) {
            return [$left['running'] || !$left['checked'], $left['description']]
                <=> [$right['running'] || !$right['checked'], $right['description']];
        });

        return $out;
    }

    /** every certificate with its expiry and its users, the first to lapse first */
    public static function certificates(array $certificates, int $now): array
    {
        $out = [];
        foreach ($certificates as $cert) {
            $days = (int)floor(((int)$cert['expires'] - $now) / 86400);
            $inUse = !empty($cert['in_use']);
            $tone = 'good';
            if ((int)$cert['expires'] < $now) {
                $tone = $inUse ? 'bad' : 'grey';
            } elseif ($inUse && $days < Health::CERT_BAD_DAYS) {
                $tone = 'bad';
            } elseif ($inUse && $days < Health::CERT_WARN_DAYS) {
                $tone = 'warn';
            }
            $out[] = [
                'name' => (string)$cert['name'],
                'expires' => (int)$cert['expires'],
                'days' => $days,
                'in_use' => $inUse,
                'users' => array_values((array)($cert['users'] ?? [])),
                'tone' => $tone,
            ];
        }
        usort($out, function ($left, $right) {
            return [!$left['in_use'], $left['expires']] <=> [!$right['in_use'], $right['expires']];
        });

        return $out;
    }

    /** what the last check found waiting, package by package */
    public static function updates(?array $product): array
    {
        $check = is_array($product['product_check'] ?? null) ? $product['product_check'] : null;
        $packages = [];
        $kinds = ['upgrade_packages', 'new_packages', 'reinstall_packages', 'downgrade_packages', 'remove_packages'];
        foreach ($kinds as $kind) {
            foreach ((array)($check[$kind] ?? []) as $package) {
                $packages[] = [
                    'name' => (string)($package['name'] ?? ''),
                    'old' => (string)($package['current_version'] ?? $package['version'] ?? ''),
                    'new' => (string)($package['new_version'] ?? $package['version'] ?? ''),
                    'kind' => str_replace('_packages', '', $kind),
                ];
            }
        }

        return [
            'version' => (string)($product['product_version'] ?? ''),
            'checked' => $check !== null,
            'packages' => $packages,
            'reboot' => !empty($check['needs_reboot']),
        ];
    }

    /** each disk and its own verdict */
    public static function disks(array $disks): array
    {
        return array_values(array_map(function ($disk) {
            $passed = $disk['state']['smart_status']['passed'] ?? null;
            return [
                'device' => (string)($disk['device'] ?? ''),
                'ident' => (string)($disk['ident'] ?? ''),
                'passed' => $passed === null ? null : (bool)$passed,
            ];
        }, array_filter($disks, 'is_array')));
    }

    /** each WireGuard peer: who, whether connected, when last seen, how much */
    public static function wireguard(array $records, array $names, int $now): array
    {
        $out = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') !== 'peer') {
                continue;
            }
            $key = (string)($record['public-key'] ?? '');
            $handshake = (int)($record['latest-handshake'] ?? 0);
            $out[] = [
                'name' => $names[$key] ?? substr($key, 0, 8),
                'interface' => (string)($record['if'] ?? ''),
                'online' => $handshake > 0 && $now - $handshake <= 300,
                'seen' => $handshake > 0 ? Duration::ago($now - $handshake) : null,
                'received' => Bytes::human((int)($record['transfer-rx'] ?? 0)),
                'sent' => Bytes::human((int)($record['transfer-tx'] ?? 0)),
            ];
        }
        usort($out, function ($left, $right) {
            return [!$left['online'], $left['name']] <=> [!$right['online'], $right['name']];
        });

        return $out;
    }
}
