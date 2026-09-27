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
use OPNsense\Lens\Destinations;
use OPNsense\Lens\DnsReport;
use PHPUnit\Framework\TestCase;

/**
 * What the network looked up, and why there is nothing when there is nothing (§4.64).
 */
class DnsReportTest extends TestCase
{
    private const ON = ['enabled' => true, 'stats' => true];

    private function raw(array $totals = [], array $clients = []): array
    {
        return ['available' => true, 'reason' => null, 'clients_read' => true, 'clients' => $clients,
                'totals' => array_merge([
                    'total' => 211680, 'passed' => 182280, 'blocklist_size' => 184233,
                    'resolved' => ['total' => 1, 'pct' => 26.7], 'blocked' => ['total' => 29400, 'pct' => 13.9],
                    'local' => ['total' => 0, 'pct' => 0.0], 'start_time' => 1790000000,
                    'top' => [['domain' => 'google.com', 'count' => 200, 'pct' => 13.0],
                              ['domain' => 'github.com', 'count' => 100, 'pct' => 6.5]],
                    'top_blocked' => [['domain' => 'ads.example', 'count' => 9, 'pct' => 100.0, 'blocklist' => 'ads']],
                ], $totals)];
    }

    public function testADnsmasqBoxIsToldWhyThereIsNothing()
    {
        $report = DnsReport::describe([], [], ['enabled' => false, 'stats' => true]);

        $this->assertSame('no_unbound', $report['state']['key']);
        $this->assertStringContainsString('dnsmasq keeps none', $report['state']['text']);
    }

    public function testStatisticsOffSaysWhereToSwitchThemOn()
    {
        $report = DnsReport::describe([], [], ['enabled' => true, 'stats' => false]);

        $this->assertSame('no_stats', $report['state']['key']);
        $this->assertSame('/ui/unbound/stats', $report['state']['link']);
    }

    public function testAStoreThatDoesNotAnswerIsNotAnEmptyOne()
    {
        $this->assertSame('unreadable', DnsReport::describe(['available' => false], [], self::ON)['state']['key']);
        $this->assertSame('empty', DnsReport::describe(
            ['available' => true, 'reason' => 'empty', 'totals' => []],
            [],
            self::ON
        )['state']['key']);
    }

    public function testFiguresAreWrittenForPeople()
    {
        $report = DnsReport::describe($this->raw(), [], self::ON);

        $this->assertSame('211,680', $report['figures']['total']);
        $this->assertSame('13.9%', $report['figures']['blocked_pct']);
        $this->assertSame('0%', $report['figures']['local_pct']);
        $this->assertSame(100.0, $report['top'][0]['bar']);
        $this->assertSame(50.0, $report['top'][1]['bar']);
        $this->assertSame('ads', $report['blocked'][0]['blocklist']);
    }

    public function testAFoldedPhoneAsksAsOneDeviceAndAnUnplacedAddressStaysAnAddress()
    {
        $rows = [['mac' => 'e6:00:00:00:00:02', 'macs' => ['e6:00:00:00:00:02', 'e6:00:00:00:00:01'],
                  'name' => 'Pixel-8', 'kind' => ['icon' => 'fa-mobile']]];
        $report = DnsReport::describe($this->raw([], [
            ['mac' => 'e6:00:00:00:00:01', 'queries' => 30, 'addresses' => ['10.0.0.61']],
            ['mac' => 'e6:00:00:00:00:02', 'queries' => 40, 'addresses' => ['10.0.0.62']],
            ['mac' => null, 'queries' => 50, 'addresses' => ['10.0.0.9']],
        ]), $rows, self::ON);

        $this->assertSame(['Pixel-8', '10.0.0.9'], array_column($report['clients'], 'name'));
        $this->assertSame('70', $report['clients'][0]['queries']);
        $this->assertSame('10.0.0.61, 10.0.0.62', $report['clients'][0]['sub']);
        $this->assertNull($report['clients'][1]['link']);
    }
}
