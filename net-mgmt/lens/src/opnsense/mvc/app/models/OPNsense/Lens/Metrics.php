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
 * Class Metrics
 *
 * Everything Lens knows, in Prometheus' text format, for people whose
 * dashboards already live in Grafana. Pure.
 *
 * Every value here is a gauge over a stated window, not a counter: Lens keeps
 * hourly buckets, not running totals, and a counter that went down when an old
 * hour aged out of the window would be read by `rate()` as a reset and turned
 * into nonsense. The window is a label, so nobody has to guess (§4.55).
 *
 * Built from the same reports every page draws from, so a Grafana panel and the
 * Lens page cannot disagree about a device (§4.37).
 *
 * @package OPNsense\Lens
 */
class Metrics
{
    /**
     * @param array $devices DeviceReport over the last 24 hours
     * @param array $segments SegmentReport over the last 24 hours
     * @param array $status `lens status`
     * @param int $now
     * @return string
     */
    public static function render(array $devices, array $segments, array $status, int $now): string
    {
        $out = [];

        self::family($out, 'lens_devices_known', 'gauge', 'Devices Lens has ever observed.');
        $out[] = sprintf('lens_devices_known %d', (int)($devices['summary']['known'] ?? 0));

        self::family($out, 'lens_devices_here', 'gauge', 'Devices seen by the last observation.');
        $out[] = sprintf('lens_devices_here %d', (int)($devices['summary']['here'] ?? 0));

        self::family($out, 'lens_device_present', 'gauge', '1 when the last observation saw the device.');
        foreach ($devices['devices'] ?? [] as $row) {
            $out[] = sprintf('lens_device_present{%s} %d', self::labels(self::who($row)), $row['here'] ? 1 : 0);
        }

        self::family(
            $out,
            'lens_device_bytes',
            'gauge',
            'Bytes attributed to a device over the window, by direction as the device sees it.'
        );
        foreach ($devices['devices'] ?? [] as $row) {
            foreach (['sent' => 'up', 'received' => 'down'] as $field => $direction) {
                $out[] = sprintf(
                    'lens_device_bytes{%s} %d',
                    self::labels(self::who($row) + ['direction' => $direction, 'window' => '24h']),
                    (int)($row[$field] ?? 0)
                );
            }
        }

        self::family($out, 'lens_segment_bytes', 'gauge', 'Bytes on an interface over the window.');
        self::family(
            $out,
            'lens_segment_named_ratio',
            'gauge',
            'Share of a segment\'s bytes Lens could attribute to a device on it.'
        );
        foreach ($segments['segments'] ?? [] as $segment) {
            $labels = [
                'interface' => (string)$segment['interface'],
                'name' => (string)$segment['name'],
                'yours' => $segment['is_network'] ? 'true' : 'false',
                'window' => '24h',
            ];
            $out[] = sprintf('lens_segment_bytes{%s} %d', self::labels($labels), (int)$segment['octets']);
            if ($segment['is_network']) {
                $out[] = sprintf(
                    'lens_segment_named_ratio{%s} %s',
                    self::labels($labels),
                    self::number((float)$segment['named_share'])
                );
            }
        }

        $baseline = $devices['baseline'] ?? [];
        self::family($out, 'lens_baseline_days', 'gauge', 'Days of history the baseline has.');
        $out[] = sprintf('lens_baseline_days %d', (int)($baseline['days'] ?? 0));
        self::family($out, 'lens_baseline_days_needed', 'gauge', 'Days it needs before it judges anything.');
        $out[] = sprintf('lens_baseline_days_needed %d', (int)($baseline['needs_days'] ?? 21));

        self::family($out, 'lens_devices_unusual', 'gauge', 'Devices moving far more than their usual day.');
        $out[] = sprintf('lens_devices_unusual %d', count($baseline['unusual'] ?? []));

        /* the one alert every scraper should have: a collector that stopped is
           a hole in history nothing can fill afterwards (§4.25) */
        self::family(
            $out,
            'lens_collector_last_run_seconds',
            'gauge',
            'Seconds since each collector duty last ran. Alert on this.'
        );
        foreach ($status['runs'] ?? [] as $duty => $run) {
            $out[] = sprintf(
                'lens_collector_last_run_seconds{%s} %d',
                self::labels(['duty' => (string)$duty, 'ok' => !empty($run['ok']) ? 'true' : 'false']),
                max(0, $now - (int)($run['at'] ?? $now))
            );
        }

        self::family($out, 'lens_store_bytes', 'gauge', 'Size of the Lens store on disk.');
        $out[] = sprintf('lens_store_bytes %d', (int)round((float)($status['size_mb'] ?? 0) * 1048576));

        return implode("\n", $out) . "\n";
    }

    private static function who(array $row): array
    {
        return [
            'mac' => (string)$row['mac'],
            'name' => (string)$row['name'],
            'kind' => (string)($row['kind']['key'] ?? 'unknown'),
        ];
    }

    private static function family(array &$out, string $name, string $type, string $help): void
    {
        $out[] = sprintf('# HELP %s %s', $name, $help);
        $out[] = sprintf('# TYPE %s %s', $name, $type);
    }

    /**
     * Label values escaped the way the exposition format requires. A device
     * named `Bennys "Küche"` must not end the label early, and a newline in a
     * note must not end the line.
     */
    private static function labels(array $labels): string
    {
        $parts = [];
        foreach ($labels as $key => $value) {
            $value = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$value);
            $parts[] = sprintf('%s="%s"', $key, $value);
        }

        return implode(',', $parts);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') ?: '0';
    }
}
