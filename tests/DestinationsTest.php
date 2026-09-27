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
use PHPUnit\Framework\TestCase;

/**
 * Who a device talks to, in words (§4.62).
 */
class DestinationsTest extends TestCase
{
    private function raw(array $rows, bool $enabled = true): array
    {
        return ['enabled' => $enabled, 'days' => 30, 'rows' => $rows];
    }

    public function testAKnownPortIsNamedAndAnUnknownOneIsLeftAsTheNumber()
    {
        $report = Destinations::describe($this->raw([
            ['peer' => '52.1.2.3', 'port' => 8883, 'protocol' => 6, 'sent' => 900, 'received' => 100, 'days' => 30],
            ['peer' => '52.1.2.4', 'port' => 40123, 'protocol' => 17, 'sent' => 10, 'received' => 0, 'days' => 2],
        ]));

        $this->assertSame('8883 TCP · MQTT over TLS', $report['rows'][0]['service']);
        $this->assertSame('40123 UDP', $report['rows'][1]['service']);
        $this->assertSame(30, $report['rows'][0]['days']);
    }

    public function testTheOtherRowAndTheTailAreKeptInTheTotal()
    {
        $rows = [['peer' => '*', 'port' => 0, 'protocol' => 0, 'sent' => 50, 'received' => 50, 'days' => 3]];
        for ($n = 0; $n < 20; $n++) {
            $rows[] = ['peer' => '10.0.0.' . $n, 'port' => 443, 'protocol' => 6,
                       'sent' => 100 - $n, 'received' => 0, 'days' => 1];
        }

        $report = Destinations::describe($this->raw($rows));

        $this->assertCount(Destinations::SHOWN, $report['rows']);
        $this->assertNotNull($report['other'], 'the tail and the summed row are said, not dropped');
        $this->assertSame('10.0.0.0', $report['rows'][0]['peer']);
        $this->assertSame(100.0, $report['rows'][0]['bar']);
    }

    public function testSwitchedOffSaysWhereToSwitchItOn()
    {
        $report = Destinations::describe($this->raw([], false));

        $this->assertFalse($report['enabled']);
        $this->assertStringContainsString('Settings', $report['note']);
    }

    public function testSwitchedOnButEmptySaysWhenTheFirstDayArrives()
    {
        $this->assertStringContainsString('day after', Destinations::describe($this->raw([]))['note']);
    }

    public function testASliverIsUnderOnePercentNotNothing()
    {
        $report = Destinations::describe($this->raw([
            ['peer' => '52.1.2.3', 'port' => 443, 'protocol' => 6, 'sent' => 1000, 'received' => 0, 'days' => 1],
            ['peer' => '9.9.9.9', 'port' => 853, 'protocol' => 6, 'sent' => 1, 'received' => 0, 'days' => 1],
        ]));

        $this->assertSame('100%', $report['rows'][0]['share_text']);
        $this->assertSame('under 1%', $report['rows'][1]['share_text']);
    }

    public function testIcmpHasNoPort()
    {
        $report = Destinations::describe($this->raw([
            ['peer' => '9.9.9.9', 'port' => 0, 'protocol' => 1, 'sent' => 1, 'received' => 1, 'days' => 1],
        ]));

        $this->assertSame('ICMP', $report['rows'][0]['service']);
    }
}
