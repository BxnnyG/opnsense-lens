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
use OPNsense\Core\ACL;
use OPNsense\Core\Config;
use OPNsense\Lens\Bytes;
use OPNsense\Lens\Health;
use OPNsense\Lens\InterfaceState;
use OPNsense\Lens\Events;
use OPNsense\Lens\Heatmap;
use OPNsense\Lens\Internet;
use OPNsense\Lens\Wan;
use OPNsense\Lens\LineQuality;
use OPNsense\Lens\PresenceReport;
use OPNsense\Lens\SystemFacts;
use OPNsense\Lens\Wall;
use OPNsense\Lens\Window;

/**
 * Class DashboardController
 *
 * Input and output only (§4.21). Two things the other pages do not already
 * provide: the network over time, and the firewall's own vital signs.
 *
 * The vital signs are read here through configd rather than by calling core's
 * own `api/diagnostics/*` from the browser. Those endpoints sit behind core's
 * privileges, not Lens's, so a user holding exactly Reporting: Lens would get a
 * dashboard with a blank system card -- §4.38's failure, arriving by a
 * different road.
 *
 * @package OPNsense\Lens\Api
 */
class DashboardController extends ApiControllerBase
{
    /**
     * @return array traffic on the operator's own segments over the window
     */
    public function timelineAction()
    {
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        return self::timeline(self::decode(new Backend(), 'lens timeline ' . $hours), $hours, time());
    }

    /**
     * @param array $raw `lens timeline` decoded
     * @param int $hours the window asked for
     * @param int $now
     * @return array the series, its step and peak, and the window
     */
    public static function timeline(array $raw, int $hours, int $now): array
    {
        $series = [];
        $peak = 0;
        foreach ($raw['series'] ?? [] as $point) {
            $sent = (int)($point['sent'] ?? 0);
            $received = (int)($point['received'] ?? 0);
            $peak = max($peak, $sent + $received);
            $series[] = ['at' => (int)($point['at'] ?? 0), 'sent' => $sent, 'received' => $received];
        }

        return [
            'series' => $series,
            'step' => (int)($raw['step'] ?? 3600),
            'peak' => $peak,
            'peak_text' => Bytes::human($peak),
            'window' => Window::describe(
                $hours,
                isset($raw['first_bucket']) ? (int)$raw['first_bucket'] : null,
                $now
            ),
        ];
    }

    /**
     * Everything the wall shows, in one request a minute (§4.71). The device
     * list's reads are made once and reused: its status, its internet and its
     * gateways. Who is home comes from the rows' own `here`, so the presence
     * spans are not read at all.
     *
     * @return array
     */
    public function wallAction()
    {
        $backend = new Backend();
        $started = microtime(true);
        $calls = [];
        $raw = [];
        $now = time();

        $list = DevicesController::report($backend, 24, $calls, $raw);
        $names = SegmentsController::names();

        $events = Events::describe(self::decode($backend, 'lens events 7'), $list['devices'], $names, $now);
        $people = PresenceReport::describe($list['devices'], [], $now)['people'];

        $internet = Internet::describe(
            [],
            $raw['internet'] ?? [],
            LineQuality::describe($raw['gateways'] ?? [], []),
            self::wan($backend),
            $now
        );

        $report = Wall::describe(
            $list,
            $events,
            $people,
            $internet,
            self::timeline(self::decode($backend, 'lens timeline 24'), 24, $now),
            $now
        );
        $report['timing'] = ['total_ms' => (int)round((microtime(true) - $started) * 1000)];

        return $report;
    }

    /**
     * @return array the internet: state, WAN addresses, public round trips, uptime
     */
    public function internetAction()
    {
        $backend = new Backend();
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        return Internet::describe(
            self::decode($backend, 'interface address'),
            self::decode($backend, 'lens internet ' . $hours),
            LineQuality::describe(self::decode($backend, 'interface gateways status'), []),
            self::wan($backend),
            time()
        );
    }

    /**
     * @return array every gateway's quality now, and over the chosen range
     */
    public function lineAction()
    {
        $backend = new Backend();
        $hours = Window::hours($this->request->get('hours', null, Window::DEFAULT_HOURS));

        return LineQuality::describe(
            self::decode($backend, 'interface gateways status'),
            self::decode($backend, 'lens gateways ' . $hours)
        );
    }

    /**
     * @return array the network's week, hour by hour
     */
    public function heatmapAction()
    {
        $raw = self::decode(new Backend(), 'lens heatmap');
        $grid = Heatmap::grid((array)($raw['heatmap'] ?? []));
        $grid['heatmap_days'] = (int)($raw['heatmap_days'] ?? 28);

        return $grid;
    }

    /**
     * @return array load, memory, disk, uptime, and the WAN counters
     */
    public function systemAction()
    {
        return self::facts(new Backend());
    }

    /** CPU, memory, disk, uptime and the WAN's counters, as the system card and the health row read them */
    private static function facts(Backend $backend): array
    {
        /* configdpRun, as core itself calls it -- the list is one parameter */
        $sysctl = json_decode(
            trim((string)$backend->configdpRun('system sysctl values', [implode(',', SystemFacts::SYSCTLS)])),
            true
        );

        return SystemFacts::assemble(
            is_array($sysctl) ? $sysctl : [],
            self::decode($backend, 'system diag disk'),
            self::decode($backend, 'interface show traffic'),
            time(),
            self::wan($backend)['v4'] ?? 'wan'
        );
    }

    /**
     * How Lens's pages write times and dates (Services: Lens: Settings), for
     * lens.js -- here, under the reporting privilege, because every page needs
     * it and a viewer need not be allowed to open the settings.
     *
     * @return array ['clock' => auto|24|12, 'date_order' => auto|dmy|mdy|ymd]
     */
    public function displayAction()
    {
        $values = (array)(self::decode(new Backend(), 'lens settings')['values'] ?? []);

        return [
            'clock' => in_array($values['clock'] ?? 'auto', ['auto', '24', '12'], true) ? $values['clock'] : 'auto',
            'date_order' => in_array($values['date_order'] ?? 'auto', ['auto', 'dmy', 'mdy', 'ymd'], true)
                ? $values['date_order'] : 'auto',
        ];
    }

    /**
     * Is everything all right (§4.84, stage 50): one tile per area, each read on
     * its own and timed, so one source that fails greys one tile, not the row.
     *
     * System, temperature and internet stand under Lens's own privilege, as the
     * system card always has (§4.38). Updates, services and certificates are what
     * core guards itself: a tile for them is shown only to a user who may open
     * core's own endpoint -- left out, not greyed, so nothing is hinted at.
     *
     * @return array ['summary', 'tiles', 'timing']
     */
    public function healthAction()
    {
        $backend = new Backend();
        $acl = new ACL();
        $user = $this->getUserName();
        $timing = [];
        $read = function (string $key, callable $source) use (&$timing) {
            $started = microtime(true);
            try {
                $tile = $source();
            } catch (\Throwable $failure) {
                $tile = ['key' => $key, 'title' => $key, 'icon' => 'fa-question', 'tone' => 'grey',
                         'sentence' => gettext('Could not be read.'), 'detail' => [], 'link' => null];
            }
            $timing[$key] = (int)round((microtime(true) - $started) * 1000);
            return $tile;
        };

        $probes = self::decode($backend, 'lens internet 24');
        $tiles = [
            $read('internet', function () use ($backend, $probes) {
                return Health::internet(Internet::describe(
                    self::decode($backend, 'interface address'),
                    $probes,
                    LineQuality::describe(self::decode($backend, 'interface gateways status'), []),
                    self::wan($backend),
                    time()
                ));
            }),
            $read('system', function () use ($backend) {
                return Health::system(self::facts($backend));
            }),
            $read('interfaces', function () use ($backend) {
                $links = SegmentsController::links($backend);
                $named = [];
                foreach (SegmentsController::enabled() as $device => $name) {
                    if (isset($links[$device])) {
                        $named[$name] = $links[$device];
                    }
                }
                return InterfaceState::tile($named);
            }),
            $read('temperature', function () use ($backend) {
                return Health::temperature(self::temperatures($backend));
            }),
        ];
        if ($acl->isPageAccessible($user, '/api/core/firmware/status')) {
            $tiles[] = $read('updates', function () use ($backend) {
                return Health::updates(self::decodeOrNull($backend, 'firmware product'));
            });
        }
        if ($acl->isPageAccessible($user, '/api/core/service/search')) {
            $tiles[] = $read('services', function () use ($backend) {
                return Health::services(self::decodeOrNull($backend, 'service list'));
            });
        }
        if ($acl->isPageAccessible($user, '/api/trust/cert/search')) {
            $tiles[] = $read('certificates', function () {
                return Health::certificates(self::certificates(), time());
            });
        }
        /* what is installed, and only then (stage 52, §4.84) */
        if (self::installed('ddclient') && $acl->isPageAccessible($user, '/api/dyndns/accounts/search_item')) {
            $tiles[] = $read('dyndns', function () use ($backend, $probes) {
                return Health::dyndns(
                    self::dyndnsAccounts(),
                    self::decodeOrNull($backend, 'ddclient statistics'),
                    is_array($probes['public'] ?? null) ? $probes['public'] : []
                );
            });
        }
        if (self::installed('smart') && $acl->isPageAccessible($user, '/api/smart/service/list')) {
            $tiles[] = $read('smart', function () use ($backend) {
                return Health::smart(self::decodeOrNull($backend, 'smart detailed list'));
            });
        }
        if ($acl->isPageAccessible($user, '/api/wireguard/service/show')) {
            $tiles[] = $read('wireguard', function () use ($backend) {
                $shown = self::decodeOrNull($backend, 'wireguard show');
                return Health::wireguard(
                    $shown === null ? null : (array)($shown['records'] ?? []),
                    self::wireguardNames(),
                    time()
                );
            });
        }
        if (self::installed('netbird') && $acl->isPageAccessible($user, '/api/netbird/status/status')) {
            $tiles[] = $read('netbird', function () use ($backend) {
                $status = self::decodeOrNull($backend, 'netbird status-json');
                return Health::netbird($status, self::netbirdReport($status));
            });
        }
        if (self::installed('tailscale') && $acl->isPageAccessible($user, '/api/tailscale/status/status')) {
            $tiles[] = $read('tailscale', function () use ($backend) {
                return Health::tailscale(self::decodeOrNull($backend, 'tailscale tailscale-status'));
            });
        }
        $tiles = array_values(array_filter($tiles));

        return ['summary' => Health::summary($tiles), 'tiles' => $tiles, 'timing' => $timing];
    }

    /**
     * The NetBird plugin's own verdict, where its version brings one
     * (StatusReport, os-netbird 1.3_x and later); null where it does not.
     */
    public static function netbirdReport(?array $status): ?array
    {
        $class = '\\OPNsense\\Netbird\\StatusReport';
        if ($status === null || !class_exists($class) || !method_exists($class, 'fromArray')) {
            return null;
        }

        return $class::fromArray($status);
    }

    /** a plugin is installed when its configd actions are */
    public static function installed(string $plugin): bool
    {
        return is_file('/usr/local/opnsense/service/conf/actions.d/actions_' . $plugin . '.conf');
    }

    /** the DynDNS accounts as config.xml holds them: uuid, description, hostnames, enabled */
    public static function dyndnsAccounts(): array
    {
        $out = [];
        $accounts = Config::getInstance()->object()->xpath('//OPNsense/DynDNS/accounts/account');
        foreach ($accounts ?: [] as $account) {
            $out[] = [
                'uuid' => (string)$account['uuid'],
                'description' => (string)$account->description,
                'hostnames' => (string)$account->hostnames,
                'enabled' => (string)$account->enabled === '1',
            ];
        }

        return $out;
    }

    /** WireGuard peers' names by public key, from config.xml -- never the models, which hold private keys */
    public static function wireguardNames(): array
    {
        $names = [];
        $clients = Config::getInstance()->object()->xpath('//OPNsense/wireguard/client/clients/client');
        foreach ($clients ?: [] as $client) {
            if ((string)$client->pubkey !== '') {
                $names[(string)$client->pubkey] = (string)$client->name;
            }
        }

        return $names;
    }

    /** sysctl name => "52.0C" for every sensor core knows; [] on a box without any */
    public static function temperatures(Backend $backend): ?array
    {
        $sensors = array_values(array_filter(array_map('trim', explode("\n", (string)$backend->configdRun(
            'system sensors'
        )))));
        if ($sensors === []) {
            return [];
        }
        $values = json_decode(
            trim((string)$backend->configdpRun('system sysctl values', [implode(',', $sensors)])),
            true
        );

        return is_array($values) ? $values : null;
    }

    /** every certificate's name, expiry and who uses it -- from the public part only (Health::usersOf) */
    public static function certificates(): array
    {
        $config = Config::getInstance()->object();
        $out = [];
        foreach ($config->cert as $cert) {
            $parsed = Health::certificate((string)$cert->crt, (string)$cert->descr);
            if ($parsed === null) {
                continue;
            }
            $parsed['users'] = Health::usersOf($config, (string)$cert->refid);
            $parsed['in_use'] = $parsed['users'] !== [];
            $out[] = $parsed;
        }

        return $out;
    }

    public static function decodeOrNull(Backend $backend, string $command): ?array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    /**
     * Wherever the default route points, per protocol (§4.75).
     */
    private static function wan(Backend $backend): array
    {
        $interfaces = [];
        foreach (Config::getInstance()->object()->interfaces->children() as $key => $node) {
            $interfaces[(string)$key] = ['if' => (string)$node->if, 'descr' => (string)$node->descr];
        }

        return Wan::pick(self::decode($backend, 'interface routes list -n json'), $interfaces);
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
