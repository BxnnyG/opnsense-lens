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


use OPNsense\Lens\SegmentReport;
use PHPUnit\Framework\TestCase;

/**
 * Traffic per network, and how much of it has a device.
 *
 * The second column is the reason this page exists. Core's Insight already
 * totals bytes per interface; the tests below are all about telling a busy
 * segment apart from one whose traffic belongs to machines that are not on it.
 */
class SegmentReportTest extends TestCase
{
    private const NAMES = [
        'lagg0_vlan24' => 'Server',
        'lagg0_vlan13' => 'SmartHome',
        'pppoe0' => 'WAN',
    ];

    private function raw(array $segments, array $networks): array
    {
        return ['hours' => 24, 'segments' => $segments, 'device_interfaces' => $networks];
    }

    private function segment(string $interface, int $octets, int $named): array
    {
        return [
            'interface' => $interface, 'octets' => $octets, 'named' => $named,
            'sent' => (int)($octets / 2), 'received' => $octets - (int)($octets / 2),
            'addresses' => 4, 'hours' => 24,
        ];
    }

    public function testASegmentLensCanAccountForIsNotFlagged()
    {
        $report = SegmentReport::describe(
            $this->raw([$this->segment('lagg0_vlan24', 1000, 950)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertSame('Server', $report['segments'][0]['name']);
        $this->assertSame(0.95, $report['segments'][0]['named_share']);
        $this->assertNull($report['segments'][0]['note']);
    }

    public function testASegmentWhoseTrafficHasNoDeviceOnItSaysSo()
    {
        /* the 103 GB question on the operator's second firewall */
        $report = SegmentReport::describe(
            $this->raw([$this->segment('lagg0_vlan24', 1000, 40)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertStringContainsString('routed through', $report['segments'][0]['note']);
    }

    public function testAnInterfaceNoDeviceWasEverSeenOnIsTheFarSideOfTheLine()
    {
        $report = SegmentReport::describe(
            $this->raw([$this->segment('pppoe0', 9000, 0)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertFalse($report['segments'][0]['is_network']);
        $this->assertStringContainsString('far side', $report['segments'][0]['note']);
    }

    public function testTheUnplacedPseudoInterfaceIsNamedRatherThanShownAsZero()
    {
        /* 805 MB of it on router-01 when this was first measured (DESIGN §1.4) */
        $report = SegmentReport::describe(
            $this->raw([$this->segment('0', 500, 0)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertSame('(unplaced)', $report['segments'][0]['name']);
        $this->assertStringContainsString('could not place', $report['segments'][0]['note']);
    }

    public function testTheFirewallTalkingToItselfIsLabelled()
    {
        $report = SegmentReport::describe(
            $this->raw([$this->segment('lo0', 70, 0)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertStringContainsString('itself', $report['segments'][0]['note']);
    }

    public function testAnInterfaceWithNoDescriptionStillReadsAsSomething()
    {
        $report = SegmentReport::describe(
            $this->raw([$this->segment('lagg0_vlan99', 10, 10)], ['lagg0_vlan99']),
            self::NAMES
        );

        $this->assertSame('VLAN 99', $report['segments'][0]['name']);
    }

    public function testTheTotalIsEveryInterfaceAndNotOnlyTheNamedOnes()
    {
        $report = SegmentReport::describe($this->raw([
            $this->segment('lagg0_vlan24', 1000, 900),
            $this->segment('pppoe0', 4000, 0),
        ], ['lagg0_vlan24']), self::NAMES);

        $this->assertSame(5000, $report['total_octets']);
    }

    public function testASegmentWithNoTrafficDoesNotDivideByZero()
    {
        $report = SegmentReport::describe(
            $this->raw([$this->segment('lagg0_vlan24', 0, 0)], ['lagg0_vlan24']),
            self::NAMES
        );

        $this->assertSame(0.0, $report['segments'][0]['named_share']);
        $this->assertNull($report['segments'][0]['note']);
    }

    public function testAnEmptyAnswerIsAnEmptyReportAndNotAnError()
    {
        $report = SegmentReport::describe([], []);

        $this->assertSame([], $report['segments']);
        $this->assertSame('0 B', $report['total']);
    }
}
