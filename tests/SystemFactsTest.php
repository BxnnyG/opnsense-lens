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


use OPNsense\Lens\SystemFacts;
use PHPUnit\Framework\TestCase;

/**
 * The firewall's vital signs, computed the way core computes them.
 *
 * A dashboard that disagreed with OPNsense's own system widget by a few percent
 * would be a dashboard nobody trusted about anything else.
 */
class SystemFactsTest extends TestCase
{
    private const NOW = 1790000000;

    private function sysctl(array $overrides = []): array
    {
        return array_merge([
            'kern.boottime' => '{ sec = 1789913600, usec = 12345 } Mon Sep 22 12:00:00 2026',
            'vm.loadavg' => '{ 0.95 0.80 0.70 }',
            'kern.smp.cpus' => '2',
            'hw.physmem' => '8589934592',
            'vm.stats.vm.v_page_count' => '2000000',
            'vm.stats.vm.v_inactive_count' => '400000',
            'vm.stats.vm.v_cache_count' => '0',
            'vm.stats.vm.v_laundry_count' => '100000',
            'vm.stats.vm.v_free_count' => '500000',
        ], $overrides);
    }

    private function facts(array $sysctl = [], array $disk = [], array $traffic = []): array
    {
        return SystemFacts::assemble($this->sysctl($sysctl), $disk, $traffic, self::NOW);
    }

    public function testMemoryUsesCoresOwnFormula()
    {
        /* (2000000 - (400000 + 0 + 100000 + 500000)) / 2000000 = half of 8 GiB */
        $memory = $this->facts()['memory'];

        $this->assertSame(50, $memory['percent']);
        $this->assertSame(4294967296, $memory['used']);
    }

    public function testLoadIsReadAgainstTheCoreCount()
    {
        /* 0.95 means "nearly full" on the operator's two-core box and nothing
           at all on a sixteen-core one */
        $load = $this->facts()['load'];

        $this->assertSame(48, $load['percent']);
        $this->assertSame(2, $load['cores']);
    }

    public function testLoadNeverReportsMoreThanAFullBox()
    {
        $this->assertSame(100, $this->facts(['vm.loadavg' => '{ 7.50 3.00 2.00 }'])['load']['percent']);
    }

    public function testUptimeIsReadFromBoottimeAsCoreParsesIt()
    {
        $uptime = $this->facts()['uptime'];

        $this->assertSame(86400, $uptime['seconds']);
    }

    public function testTheRootFilesystemIsTheOneReported()
    {
        $disk = $this->facts([], ['devices' => [
            ['mountpoint' => '/var/log', 'used_pct' => 99, 'used_bytes' => 1, 'total_bytes' => 1],
            ['mountpoint' => '/', 'used_pct' => 23, 'used_bytes' => 5 * 1073741824,
             'total_bytes' => 22 * 1073741824],
        ]])['disk'];

        $this->assertSame(23, $disk['percent']);
    }

    public function testTheWanCountersArePassedWithTheMomentTheyWereRead()
    {
        $wan = $this->facts([], [], [
            'time' => 1790000000.25,
            'interfaces' => ['wan' => [
                'bytes received' => 9000, 'bytes transmitted' => 1000, 'name' => 'WAN',
            ]],
        ])['wan'];

        $this->assertSame(9000, $wan['received']);
        $this->assertSame(1000, $wan['sent']);
        $this->assertSame(1790000000.25, $wan['at']);
    }

    public function testMissingValuesAreNullRatherThanZero()
    {
        /* a blank meter says "unknown"; a meter at 0% says "idle", and only one
           of those is true when the reading failed */
        $facts = SystemFacts::assemble([], [], [], self::NOW);

        $this->assertNull($facts['memory']);
        $this->assertNull($facts['load']);
        $this->assertNull($facts['disk']);
        $this->assertNull($facts['wan']);
        $this->assertNull($facts['uptime']);
    }

    public function testAMalformedLoadAverageIsUnknownNotZero()
    {
        $this->assertNull($this->facts(['vm.loadavg' => 'garbage'])['load']);
    }
}
