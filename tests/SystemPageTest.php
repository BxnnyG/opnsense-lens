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
use OPNsense\Lens\SystemDetail;
use OPNsense\Lens\SystemHistory;
use PHPUnit\Framework\TestCase;

/**
 * What stands behind the health row's tiles (stage 53): core's RRD history at
 * the right resolution, and the lists, in Lens.
 */
class SystemPageTest extends TestCase
{
    private const NOW = 1791500000;

    /** fetchData.py's shape: one set per AVERAGE archive, values in milliseconds */
    private function fetch(): array
    {
        $set = function (int $step, int $rows) {
            $values = [];
            for ($i = $rows - 1; $i >= 0; $i--) {
                $values[] = [(self::NOW - $i * $step) * 1000, $i === 3 ? null : 10.0 + $i % 5];
            }
            return ['step_size' => $step, 'recorded_time' => $step * ($rows - 1),
                    'ds' => [['key' => 'user', 'values' => $values], ['key' => 'system', 'values' => $values]]];
        };

        return ['step' => 60, 'sets' => [$set(86400, 400), $set(60, 1200), $set(300, 2000)]];
    }

    public function testTheFinestArchiveThatCoversTheRangeIsChosen()
    {
        // a day: one-minute rows reach back 20 hours only, five-minute rows a week
        $day = SystemHistory::series($this->fetch(), 24, self::NOW);
        $this->assertSame(300, $day['step']);
        $this->assertSame(['user', 'system'], array_column($day['series'], 'key'));
        $first = $day['series'][0]['points'][0][0];
        $this->assertGreaterThanOrEqual(self::NOW - 86400, $first);
        // a gap stays a gap
        $this->assertContains(null, array_column($day['series'][0]['points'], 1));

        // a year is more than any archive holds: the one that reaches furthest
        $this->assertSame(86400, SystemHistory::series($this->fetch(), 24 * 400, self::NOW)['step']);
        $this->assertSame(['step' => 0, 'series' => []], SystemHistory::series([], 24, self::NOW));
    }

    public function testAWeekAtFiveMinutesIsThinnedForTheScreen()
    {
        $week = SystemHistory::series($this->fetch(), 168, self::NOW);
        $this->assertLessThanOrEqual(SystemHistory::POINTS, count($week['series'][0]['points']));
    }

    public function testProcessorMemoryAndTemperatureAreChosenFromWhatTheBoxHas()
    {
        $chosen = SystemHistory::chosen([
            'system-processor' => ['topic' => 'system', 'itemName' => 'processor', 'filename' => 'system-processor.rrd',
                                   'title' => 'Processor', 'field_units' => ['user' => '%']],
            'system-memory' => ['topic' => 'system', 'itemName' => 'memory', 'filename' => 'system-memory.rrd'],
            'wan-traffic' => ['topic' => 'traffic', 'itemName' => 'wan', 'filename' => 'wan-traffic.rrd'],
            'cputemp-temperature' => ['topic' => 'temperature', 'itemName' => 'cputemp',
                                      'filename' => 'cputemp-temperature.rrd'],
        ]);
        $this->assertSame(['processor', 'memory', 'temperature'], array_keys($chosen));
        $this->assertSame(['user' => '%'], $chosen['processor']['units']);
        $this->assertSame('Memory', $chosen['memory']['title']);
    }

    public function testServicesStoppedFirstAndUncheckedApart()
    {
        $list = SystemDetail::services([
            ['name' => 'unbound', 'description' => 'Unbound DNS', 'status' => 'unbound is running as pid 1.'],
            ['name' => 'configd', 'description' => 'Configd', 'status' => '', 'locked' => 1],
            ['name' => 'ntpd', 'description' => 'NTP', 'status' => 'ntpd is not running.'],
        ]);
        $this->assertSame(['NTP', 'Configd', 'Unbound DNS'], array_column($list, 'description'));
        $this->assertFalse($list[0]['running']);
        $this->assertFalse($list[1]['checked']);
    }

    public function testCertificatesInUseFirstWithTheirTone()
    {
        $day = 86400;
        $list = SystemDetail::certificates([
            ['name' => 'old', 'expires' => self::NOW - $day, 'in_use' => false],
            ['name' => 'web', 'expires' => self::NOW + 200 * $day, 'in_use' => true, 'users' => ['system.webgui']],
            ['name' => 'vpn', 'expires' => self::NOW + 5 * $day, 'in_use' => true, 'users' => ['OpenVPN']],
        ], self::NOW);
        $this->assertSame(['vpn', 'web', 'old'], array_column($list, 'name'));
        $this->assertSame(['warn', 'good', 'grey'], array_column($list, 'tone'));
        $this->assertSame(5, $list[0]['days']);
    }

    public function testUpdatesDisksAndPeers()
    {
        $updates = SystemDetail::updates(['product_version' => '26.7.2', 'product_check' => [
            'upgrade_packages' => [['name' => 'opnsense', 'current_version' => '26.7.2', 'new_version' => '26.7.3']],
            'needs_reboot' => 1]]);
        $this->assertSame([['name' => 'opnsense', 'old' => '26.7.2', 'new' => '26.7.3', 'kind' => 'upgrade']],
            $updates['packages']);
        $this->assertTrue($updates['reboot']);
        $this->assertFalse(SystemDetail::updates(['product_check' => null])['checked']);

        $disks = SystemDetail::disks([['device' => 'ada0', 'ident' => 'X', 'state' => ['smart_status' => ['passed' => true]]],
                                      ['device' => 'da0', 'ident' => 'USB', 'state' => []]]);
        $this->assertSame([true, null], array_column($disks, 'passed'));

        $peers = SystemDetail::wireguard([
            ['type' => 'peer', 'if' => 'wg0', 'public-key' => 'K1', 'latest-handshake' => self::NOW - 4000],
            ['type' => 'peer', 'if' => 'wg0', 'public-key' => 'K2', 'latest-handshake' => self::NOW - 30,
             'transfer-rx' => 2048, 'transfer-tx' => 1024],
        ], ['K2' => 'Anna phone'], self::NOW);
        $this->assertSame('Anna phone', $peers[0]['name']);
        $this->assertTrue($peers[0]['online']);
        $this->assertFalse($peers[1]['online']);
    }
}
