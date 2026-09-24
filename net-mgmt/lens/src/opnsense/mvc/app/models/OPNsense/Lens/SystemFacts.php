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
 * Class SystemFacts
 *
 * The firewall's own vital signs, for the dashboard. Pure.
 *
 * Every figure here is computed the way OPNsense core computes it, read from
 * core's source on 2026-09-24 rather than reinvented: memory from the same five
 * page counters as `Diagnostics\Api\SystemController::systemResourcesAction`,
 * uptime from `kern.boottime` as `systemTimeAction` parses it, disk from
 * `system diag disk`, and interface counters from `interface show traffic`
 * (§4.51). A dashboard that disagreed with core's own widget by a few percent
 * would be a dashboard nobody trusted about anything else.
 *
 * @package OPNsense\Lens
 */
class SystemFacts
{
    /** the sysctls this needs, in one configd call */
    public const SYSCTLS = [
        'kern.boottime', 'vm.loadavg', 'kern.smp.cpus', 'hw.physmem',
        'vm.stats.vm.v_page_count', 'vm.stats.vm.v_inactive_count',
        'vm.stats.vm.v_cache_count', 'vm.stats.vm.v_laundry_count',
        'vm.stats.vm.v_free_count',
    ];

    /**
     * @param array $sysctl `system sysctl values` decoded
     * @param array $disk `system diag disk` decoded
     * @param array $traffic `interface show traffic` decoded
     * @param int $now
     * @return array
     */
    public static function assemble(array $sysctl, array $disk, array $traffic, int $now): array
    {
        return [
            'uptime' => self::uptime($sysctl, $now),
            'load' => self::load($sysctl),
            'memory' => self::memory($sysctl),
            'disk' => self::disk($disk),
            'wan' => self::wan($traffic),
        ];
    }

    private static function uptime(array $sysctl, int $now): ?array
    {
        if (!preg_match('/sec = (\d+)/', (string)($sysctl['kern.boottime'] ?? ''), $found)) {
            return null;
        }

        $seconds = max(0, $now - (int)$found[1]);

        return ['seconds' => $seconds, 'text' => Duration::span($seconds)];
    }

    /**
     * Load divided by cores, because "1.9" means nothing until you know whether
     * the box has two cores or sixteen -- and on the operator's two-core box it
     * means nearly full.
     */
    private static function load(array $sysctl): ?array
    {
        $parts = preg_split('/\s+/', trim((string)($sysctl['vm.loadavg'] ?? '')));
        if (count($parts) !== 5 || $parts[0] !== '{' || $parts[4] !== '}') {
            return null;
        }

        $cores = max(1, (int)($sysctl['kern.smp.cpus'] ?? 1));
        $one = (float)$parts[1];

        return [
            'one' => $one,
            'five' => (float)$parts[2],
            'fifteen' => (float)$parts[3],
            'cores' => $cores,
            'percent' => (int)round(min(100.0, $one / $cores * 100)),
        ];
    }

    private static function memory(array $sysctl): ?array
    {
        $pages = (float)($sysctl['vm.stats.vm.v_page_count'] ?? 0);
        $total = (float)($sysctl['hw.physmem'] ?? 0);

        if ($pages <= 0 || $total <= 0) {
            return null;
        }

        /* core's formula, unchanged: used = pages not inactive, cached,
           laundered or free, scaled to physical memory */
        $idle = (float)($sysctl['vm.stats.vm.v_inactive_count'] ?? 0)
            + (float)($sysctl['vm.stats.vm.v_cache_count'] ?? 0)
            + (float)($sysctl['vm.stats.vm.v_laundry_count'] ?? 0)
            + (float)($sysctl['vm.stats.vm.v_free_count'] ?? 0);
        $used = (($pages - $idle) / $pages) * $total;

        return [
            'used' => (int)round($used),
            'total' => (int)$total,
            'percent' => (int)round($used / $total * 100),
            'text' => sprintf('%s / %s', Bytes::human((int)$used), Bytes::human((int)$total)),
        ];
    }

    /**
     * The root filesystem. On ZFS that is the dataset mounted at `/`, which is
     * the one that fills up and stops a firewall writing logs.
     */
    private static function disk(array $disk): ?array
    {
        foreach ($disk['devices'] ?? [] as $device) {
            if (($device['mountpoint'] ?? '') !== '/') {
                continue;
            }

            return [
                'percent' => (int)($device['used_pct'] ?? 0),
                'text' => sprintf(
                    '%s / %s',
                    Bytes::human((int)($device['used_bytes'] ?? 0)),
                    Bytes::human((int)($device['total_bytes'] ?? 0))
                ),
            ];
        }

        return null;
    }

    /**
     * Cumulative counters and the moment they were read. A rate needs two
     * readings; the page takes the second one itself a few seconds later.
     */
    private static function wan(array $traffic): ?array
    {
        $wan = $traffic['interfaces']['wan'] ?? null;
        if (!is_array($wan)) {
            return null;
        }

        return [
            'name' => (string)($wan['name'] ?? 'WAN'),
            'received' => (int)($wan['bytes received'] ?? 0),
            'sent' => (int)($wan['bytes transmitted'] ?? 0),
            'at' => (float)($traffic['time'] ?? 0),
        ];
    }
}
