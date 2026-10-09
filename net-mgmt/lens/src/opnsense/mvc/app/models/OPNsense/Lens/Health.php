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
 * Class Health
 *
 * Is everything all right (§4.84, stage 50): one tile per area of the firewall,
 * each with a tone -- good, warn, bad, or grey when it could not be read -- a
 * sentence that says why, and the core page that fixes it. Lens reads what core
 * already measures and keeps none of it. Pure: the controller hands in core's
 * answers as they came.
 *
 * @package OPNsense\Lens
 */
class Health
{
    public const DISK_WARN = 85;
    public const DISK_BAD = 95;
    public const MEMORY_WARN = 90;
    public const LOAD_WARN = 1.5;
    public const TEMP_WARN = 80.0;
    public const TEMP_BAD = 90.0;
    public const CERT_WARN_DAYS = 14;
    public const CERT_BAD_DAYS = 3;

    private const RANK = ['good' => 0, 'grey' => 1, 'warn' => 2, 'bad' => 3];

    /**
     * CPU, memory and disk, as SystemFacts read them for the dashboard.
     *
     * @param array|null $facts SystemFacts::assemble(), null when it could not be read
     */
    public static function system(?array $facts): array
    {
        $tile = self::tile('system', gettext('System'), 'fa-server', '/ui/diagnostics/systemhealth');
        if ($facts === null || ($facts['memory'] ?? null) === null && ($facts['disk'] ?? null) === null) {
            return self::grey($tile, gettext('The system figures did not answer.'));
        }

        $disk = $facts['disk']['percent'] ?? null;
        $memory = $facts['memory']['percent'] ?? null;
        $load = $facts['load'] ?? null;
        $busy = $load !== null && (int)($load['cores'] ?? 0) > 0
            && (float)$load['fifteen'] > self::LOAD_WARN * (int)$load['cores'];

        $tile['detail'] = array_values(array_filter([
            $disk !== null ? sprintf(gettext('disk %d%%'), $disk) : null,
            $memory !== null ? sprintf(gettext('memory %d%%'), $memory) : null,
            $load !== null ? sprintf(gettext('load %s'), number_format((float)$load['fifteen'], 2)) : null,
            isset($facts['uptime']['text']) ? sprintf(gettext('up %s'), $facts['uptime']['text']) : null,
        ]));

        if ($disk !== null && $disk >= self::DISK_BAD) {
            return self::say($tile, 'bad', sprintf(
                gettext('The disk is %d%% full: logs and updates will start to fail.'),
                $disk
            ));
        }
        if ($disk !== null && $disk >= self::DISK_WARN) {
            return self::say($tile, 'warn', sprintf(gettext('The disk is %d%% full.'), $disk));
        }
        if ($memory !== null && $memory >= self::MEMORY_WARN) {
            return self::say($tile, 'warn', sprintf(gettext('Memory is %d%% in use.'), $memory));
        }
        if ($busy) {
            return self::say($tile, 'warn', sprintf(
                gettext('Busy for the last fifteen minutes: load %s on %d cores.'),
                number_format((float)$load['fifteen'], 2),
                (int)$load['cores']
            ));
        }

        return self::say($tile, 'good', gettext('Disk, memory and processor have room.'));
    }

    /**
     * The hottest sensor. A box without sensors -- a VM -- has no tile at all:
     * nothing to say is not "fine".
     *
     * @param array|null $readings sysctl name => "52.0C", null when unreadable
     */
    public static function temperature(?array $readings): ?array
    {
        $tile = self::tile(
            'temperature',
            gettext('Temperature'),
            'fa-thermometer-half',
            '/ui/diagnostics/systemhealth'
        );
        if ($readings === null) {
            return self::grey($tile, gettext('The sensors did not answer.'));
        }

        $hottest = null;
        foreach ($readings as $name => $value) {
            $celsius = (float)trim(str_replace('C', '', (string)$value));
            if ($celsius <= 0 || $celsius > 150) {
                continue;
            }
            if ($hottest === null || $celsius > $hottest[1]) {
                $hottest = [(string)$name, $celsius];
            }
        }
        if ($hottest === null) {
            return null;
        }

        $tile['detail'] = [sprintf('%s: %s °C', self::sensor($hottest[0]), number_format($hottest[1], 0))];
        $sentence = sprintf(gettext('Hottest sensor at %s °C.'), number_format($hottest[1], 0));
        if ($hottest[1] >= self::TEMP_BAD) {
            return self::say($tile, 'bad', $sentence);
        }

        return self::say($tile, $hottest[1] >= self::TEMP_WARN ? 'warn' : 'good', $sentence);
    }

    /** a sensor named as core's own temperature widget labels it: "CPU 1", "Zone 0" */
    private static function sensor(string $name): string
    {
        $number = preg_match('/(\d+)/', $name, $match) ? ' ' . $match[1] : '';
        $labels = [
            'dev.cpu.' => gettext('CPU'),
            'hw.acpi.' => gettext('Zone'),
            'dev.amdtemp.' => gettext('AMD'),
            'dev.pchtherm.' => gettext('Platform'),
        ];
        foreach ($labels as $prefix => $label) {
            if (strpos($name, $prefix) === 0) {
                return $label . $number;
            }
        }

        return $name;
    }

    /**
     * What core's last firmware check found -- read, never started: checking is
     * the operator's button on System: Firmware.
     *
     * @param array|null $product `firmware product` decoded
     */
    public static function updates(?array $product): array
    {
        $tile = self::tile('updates', gettext('Updates'), 'fa-refresh', '/ui/core/firmware#status');
        if ($product === null) {
            return self::grey($tile, gettext('The firmware status did not answer.'));
        }
        $version = (string)($product['product_version'] ?? '');
        $tile['detail'] = $version !== '' ? [sprintf(gettext('running %s'), $version)] : [];

        $check = $product['product_check'] ?? null;
        if (!is_array($check)) {
            return self::grey($tile, gettext('Not checked for updates yet.'));
        }
        foreach (['connection', 'repository'] as $key) {
            if (isset($check[$key]) && $check[$key] !== 'ok') {
                return self::say($tile, 'warn', gettext('The last check for updates failed.'));
            }
        }

        $count = 0;
        $kinds = ['new_packages', 'reinstall_packages', 'upgrade_packages', 'downgrade_packages', 'remove_packages'];
        foreach ($kinds as $kind) {
            $count += count((array)($check[$kind] ?? []));
        }
        $reboot = !empty($check['needs_reboot']);
        if ($count === 0) {
            $count = count((array)($check['upgrade_sets'] ?? []));
            $reboot = !empty($check['upgrade_needs_reboot']);
        }
        if ($count === 0) {
            return self::say($tile, 'good', gettext('Up to date.'));
        }

        return self::say($tile, 'warn', ($count === 1
                ? gettext('One update is waiting.')
                : sprintf(gettext('%d updates are waiting.'), $count))
            . ($reboot ? ' ' . gettext('It needs a reboot.') : ''));
    }

    /**
     * Services that are switched on and not running. One that core itself does
     * not check (nocheck, locked) is not counted either way.
     *
     * @param array|null $services `service list` decoded
     */
    public static function services(?array $services): array
    {
        $tile = self::tile('services', gettext('Services'), 'fa-cogs', '/ui/core/service');
        if ($services === null) {
            return self::grey($tile, gettext('The service list did not answer.'));
        }

        $stopped = [];
        $checked = 0;
        foreach ($services as $service) {
            if (!is_array($service) || !empty($service['nocheck']) || !empty($service['locked'])) {
                continue;
            }
            $checked++;
            if (strpos((string)($service['status'] ?? ''), 'is running') === false) {
                $stopped[] = (string)($service['description'] ?? $service['name'] ?? '?');
            }
        }

        $tile['detail'] = [sprintf(gettext('%d checked'), $checked)];
        if ($stopped === []) {
            return self::say($tile, 'good', sprintf(gettext('All %d services are running.'), $checked));
        }
        $tile['detail'] = array_merge($stopped, $tile['detail']);

        return self::say($tile, 'bad', count($stopped) === 1
            ? sprintf(gettext('%s is not running.'), $stopped[0])
            : sprintf(gettext('%d services are not running: %s.'), count($stopped), implode(', ', $stopped)));
    }

    /**
     * Certificates in use and when the first of them expires. One nobody uses
     * may lapse in peace; it is counted, not warned about.
     *
     * @param array|null $certificates [['name', 'expires' => unix|null, 'in_use' => bool]]
     */
    public static function certificates(?array $certificates, int $now): ?array
    {
        $tile = self::tile('certificates', gettext('Certificates'), 'fa-certificate', '/ui/trust/cert');
        if ($certificates === null) {
            return self::grey($tile, gettext('The certificates could not be read.'));
        }

        $used = array_values(array_filter($certificates, function ($cert) {
            return !empty($cert['in_use']) && isset($cert['expires']);
        }));
        if ($used === []) {
            return $certificates === [] ? null : self::say($tile, 'good', gettext('No certificate is in use.'));
        }
        usort($used, function ($left, $right) {
            return $left['expires'] <=> $right['expires'];
        });

        $first = $used[0];
        $days = (int)floor(($first['expires'] - $now) / 86400);
        $tile['detail'] = [sprintf(gettext('%d in use'), count($used))];
        $lapsed = count(array_filter($certificates, function ($cert) use ($now) {
            return empty($cert['in_use']) && isset($cert['expires']) && $cert['expires'] < $now;
        }));
        if ($lapsed > 0) {
            $tile['detail'][] = sprintf(gettext('%d expired, unused'), $lapsed);
        }

        if ($first['expires'] < $now) {
            return self::say($tile, 'bad', sprintf(gettext('%s has expired.'), $first['name']));
        }
        $sentence = $days < 1
            ? sprintf(gettext('%s expires today.'), $first['name'])
            : sprintf(gettext('%s expires in %d days.'), $first['name'], $days);
        if ($days < self::CERT_BAD_DAYS) {
            return self::say($tile, 'bad', $sentence);
        }
        if ($days < self::CERT_WARN_DAYS) {
            return self::say($tile, 'warn', $sentence);
        }

        return self::say($tile, 'good', count($used) === 1
            ? sprintf(gettext('The one in use is valid for %d more days.'), $days)
            : sprintf(gettext('All %d in use are valid; the first expires in %d days.'), count($used), $days));
    }

    /**
     * One certificate's name and expiry from its base64 PEM -- the public part
     * only; Lens never reads a private key (plan stage 50 §2).
     *
     * @return array|null ['name', 'expires']
     */
    public static function certificate(string $crt, string $descr): ?array
    {
        $pem = base64_decode($crt, true);
        if ($pem === false || $pem === '') {
            return null;
        }
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return null;
        }
        $name = trim($descr) !== '' ? trim($descr) : (string)($parsed['subject']['CN'] ?? '?');

        return ['name' => $name, 'expires' => (int)$parsed['validTo_time_t']];
    }

    /**
     * The internet tile, from what Internet::describe() already decided.
     */
    public static function internet(?array $internet): array
    {
        $tile = self::tile('internet', gettext('Internet'), 'fa-globe', '/ui/lens/dashboard');
        if ($internet === null || !isset($internet['state']['key'])) {
            return self::grey($tile, gettext('The internet check did not answer.'));
        }
        $tone = ['up' => 'good', 'degraded' => 'warn', 'down' => 'bad'][$internet['state']['key']] ?? 'grey';
        $percent = $internet['uptime']['percent'] ?? null;
        if ($percent !== null) {
            $tile['detail'] = [sprintf(gettext('%s%% up today'), $percent)];
        }

        return self::say($tile, $tone, (string)$internet['state']['text']);
    }

    /**
     * The row's verdict: the worst tile leads, and "all is well" is only said
     * when every tile is good -- a grey one is not good (§4.84).
     *
     * @param array $tiles tiles, nulls already removed
     */
    public static function summary(array $tiles): array
    {
        $worst = 'good';
        $lead = null;
        foreach ($tiles as $tile) {
            if (self::RANK[$tile['tone']] > self::RANK[$worst]) {
                $worst = $tile['tone'];
                $lead = $tile;
            }
        }
        $troubled = array_values(array_filter($tiles, function ($tile) {
            return in_array($tile['tone'], ['warn', 'bad'], true);
        }));

        if ($worst === 'good') {
            return ['tone' => 'good', 'sentence' => gettext('Everything on this firewall is all right.'),
                    'link' => null];
        }
        if ($worst === 'grey') {
            /* grey says why, in the tile's own words: "not checked yet" is not "unreadable" */
            return ['tone' => 'grey', 'sentence' => sprintf(
                gettext('Nothing is wrong that Lens can see. %s: %s'),
                $lead['title'],
                $lead['sentence']
            ), 'link' => $lead['link']];
        }

        return [
            'tone' => $worst,
            'sentence' => count($troubled) === 1
                ? $lead['sentence']
                : sprintf(gettext('%s And %d more need a look.'), $lead['sentence'], count($troubled) - 1),
            'link' => $lead['link'],
        ];
    }

    private static function tile(string $key, string $title, string $icon, string $link): array
    {
        return ['key' => $key, 'title' => $title, 'icon' => $icon, 'tone' => 'grey', 'sentence' => '',
                'detail' => [], 'link' => $link];
    }

    private static function say(array $tile, string $tone, string $sentence): array
    {
        $tile['tone'] = $tone;
        $tile['sentence'] = $sentence;

        return $tile;
    }

    private static function grey(array $tile, string $sentence): array
    {
        return self::say($tile, 'grey', $sentence);
    }
}
