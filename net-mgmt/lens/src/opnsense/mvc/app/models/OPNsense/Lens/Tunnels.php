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
 * Class Tunnels
 *
 * OpenVPN, IPsec and CARP (stage 59): what is configured, read from
 * config.xml, set against what is running, as core's own status pages read
 * it. Each gives rows -- one per instance, connection or virtual address, with
 * a tone -- for the System page, and the tile is judged on those rows, so the
 * tile and its list can never disagree. Pure.
 *
 * Only what is expected to be up is judged: a road-warrior server with nobody
 * connected, or an IPsec tunnel that waits for traffic, is no fault (§4.84).
 *
 * @package OPNsense\Lens
 */
class Tunnels
{
    /** an address that means "anyone": a road-warrior's far end */
    private const ANYONE = ['', '%any', '0.0.0.0', '::'];

    /**
     * Enabled OpenVPN instances, legacy and current, as core's
     * ServiceController::getConfigs() indexes them: legacy by vpnid, current
     * by uuid. Only names and roles are read, never a key.
     *
     * @return array [[id, role, name], ...]
     */
    public static function openvpnConfigured(\SimpleXMLElement $config): array
    {
        $out = [];
        foreach (['server', 'client'] as $role) {
            foreach ($config->xpath('//openvpn/openvpn-' . $role) ?: [] as $node) {
                $id = (string)$node->vpnid;
                if ($id !== '' && (string)$node->disable === '') {
                    $out[] = [$id, $role, (string)$node->description ?: sprintf('%s %s', $role, $id)];
                }
            }
        }
        foreach ($config->xpath('//OPNsense/OpenVPN/Instances/Instance') ?: [] as $node) {
            $role = (string)$node->role;
            if ((string)$node->enabled === '1' && in_array($role, ['server', 'client'], true)) {
                $out[] = [(string)$node['uuid'], $role,
                          (string)$node->description ?: sprintf('%s %s', $role, (string)$node->vpnid)];
            }
        }

        return $out;
    }

    /**
     * One row per instance, and one per client connected to a server.
     *
     * @param array $connections `openvpn connections client,server` decoded
     * @param array $configured openvpnConfigured()
     */
    public static function openvpn(array $connections, array $configured): array
    {
        $rows = [];
        foreach ($configured as [$id, $role, $name]) {
            $live = $connections[$role][$id] ?? null;
            $status = strtolower((string)($live['status'] ?? 'failed'));
            if ($live === null || $status === 'failed') {
                $rows[] = self::row($name, '', 'bad', gettext('not running'), $role);
                continue;
            }
            if ($role === 'client') {
                $rows[] = $status === 'connected'
                    ? self::row($name, (string)($live['virtual_address'] ?? ''), 'good', gettext('connected'), $role)
                    /* connecting, reconnecting, wait, auth: up, but not through to its server */
                    : self::row($name, (string)($live['real_address'] ?? ''), 'warn', $status, $role);
                continue;
            }
            $clients = (array)($live['client_list'] ?? []);
            $rows[] = self::row($name, '', 'good', sprintf(gettext('%d connected'), count($clients)), $role);
            foreach ($clients as $client) {
                $rows[] = self::row(
                    '  ' . (string)($client['common_name'] ?? '?'),
                    (string)($client['virtual_address'] ?? ''),
                    'good',
                    gettext('connected'),
                    (string)($client['real_address'] ?? '')
                );
            }
        }

        return $rows;
    }

    /**
     * Enabled IPsec connections, and whether each is expected to be up on its
     * own: a far end that is a real address, and -- for a current connection
     * -- a child that starts it rather than waiting for traffic (`trap`,
     * `route`, `none`). Legacy phase 1 entries start on their own unless
     * mobile.
     *
     * @return array [[id, name, remote, expected], ...]; id as core's
     *   SessionsController reads it: the uuid, or the legacy ikeid
     */
    public static function ipsecConfigured(\SimpleXMLElement $config): array
    {
        $out = [];
        foreach ($config->xpath('//ipsec/phase1') ?: [] as $node) {
            $id = (string)$node->ikeid;
            if ($id === '' || isset($node->disabled)) {
                continue;
            }
            $remote = (string)$node->{'remote-gateway'};
            $out[] = [$id, (string)$node->descr ?: 'con' . $id, $remote,
                      !isset($node->mobile) && !in_array($remote, self::ANYONE, true)];
        }
        $starts = [];
        foreach ($config->xpath('//OPNsense/Swanctl/children/child') ?: [] as $child) {
            if ((string)$child->enabled === '1' && strpos((string)$child->start_action, 'start') !== false) {
                $starts[(string)$child->connection] = true;
            }
        }
        foreach ($config->xpath('//OPNsense/Swanctl/Connections/Connection') ?: [] as $node) {
            if ((string)$node->enabled !== '1') {
                continue;
            }
            $uuid = (string)$node['uuid'];
            $remote = (string)$node->remote_addrs;
            $out[] = [$uuid, (string)$node->description ?: $uuid, $remote,
                      !in_array($remote, self::ANYONE, true) && isset($starts[$uuid])];
        }

        return $out;
    }

    /**
     * One row per configured connection: up when it has a security
     * association, as core's sessions page says "connected".
     *
     * @param array $status `ipsec list status` decoded
     * @param array $configured ipsecConfigured()
     */
    public static function ipsec(array $status, array $configured): array
    {
        $sas = [];
        foreach ($status as $name => $payload) {
            /* core: a uuid is the connection, a legacy name "con<ikeid>-..." its phase 1 */
            $id = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $name)
                ? $name : substr(explode('-', (string)$name)[0], 3);
            $sas[$id] = ($sas[$id] ?? 0) + count((array)($payload['sas'] ?? []));
        }
        $rows = [];
        foreach ($configured as [$id, $name, $remote, $expected]) {
            $up = ($sas[$id] ?? 0) > 0;
            $far = in_array($remote, self::ANYONE, true) ? gettext('road warriors') : $remote;
            if ($up) {
                $rows[] = self::row($name, $far, 'good', in_array($remote, self::ANYONE, true)
                    ? sprintf(gettext('%d connected'), $sas[$id]) : gettext('connected'), '');
            } elseif ($expected) {
                $rows[] = self::row($name, $far, 'warn', gettext('not connected'), '');
            } else {
                $rows[] = self::row($name, $far, 'grey', in_array($remote, self::ANYONE, true)
                    ? gettext('nobody connected') : gettext('comes up on traffic'), '');
            }
        }

        return $rows;
    }

    /**
     * One row per CARP virtual address: its state on this firewall, as core's
     * Interfaces: Virtual IPs: Status reads `interface list ifconfig`. A
     * configured address the kernel does not list is DISABLED there.
     *
     * @param array $ifconfig `interface list ifconfig` decoded, by device
     * @param array $vips configured CARP addresses: [[vhid, subnet, interface name], ...]
     * @param array $names device => interface name
     */
    public static function carp(array $ifconfig, array $vips, array $names): array
    {
        $rows = [];
        $seen = [];
        foreach ($ifconfig as $device => $data) {
            $carp = (array)($data['carp'] ?? []);
            if ($carp === []) {
                continue;
            }
            foreach (['ipv4', 'ipv6'] as $family) {
                foreach ((array)($data[$family] ?? []) as $subnet) {
                    $vhid = (string)($subnet['vhid'] ?? '');
                    if ($vhid === '' || !isset($carp[$vhid])) {
                        continue;
                    }
                    $seen[$vhid] = true;
                    $state = strtoupper((string)($carp[$vhid]['status'] ?? ''));
                    $rows[] = self::row(
                        sprintf(gettext('%s, VHID %s'), $names[$device] ?? $device, $vhid),
                        (string)($subnet['ipaddr'] ?? ''),
                        ['MASTER' => 'good', 'BACKUP' => 'good', 'INIT' => 'bad'][$state] ?? 'warn',
                        $state,
                        ''
                    );
                }
            }
        }
        foreach ($vips as [$vhid, $subnet, $interface]) {
            if (!isset($seen[(string)$vhid])) {
                $name = sprintf(gettext('%s, VHID %s'), $interface, $vhid);
                $rows[] = self::row($name, $subnet, 'warn', 'DISABLED', '');
            }
        }

        return $rows;
    }

    /** CARP addresses as configured: [[vhid, subnet, interface name], ...] */
    public static function carpConfigured(\SimpleXMLElement $config): array
    {
        $out = [];
        foreach ($config->xpath('//virtualip/vip') ?: [] as $vip) {
            if ((string)$vip->mode !== 'carp' || (string)$vip->vhid === '') {
                continue;
            }
            $interface = (string)$vip->interface;
            $descr = (string)($config->interfaces->$interface->descr ?? '');
            $out[] = [(string)$vip->vhid, (string)$vip->subnet, $descr !== '' ? $descr : strtoupper($interface)];
        }

        return $out;
    }

    private static function row(string $name, string $ip, string $tone, string $state, string $how): array
    {
        return ['name' => $name, 'ip' => $ip, 'tone' => $tone, 'online' => $tone === 'good',
                'state' => $state, 'how' => $how];
    }
}
