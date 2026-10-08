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
use OPNsense\Lens\Events;
use PHPUnit\Framework\TestCase;

/**
 * What happened, on one time line (§4.63).
 */
class EventsTest extends TestCase
{
    private const NOW = 1790000000;

    private function row(string $mac, string $name, array $extra = []): array
    {
        return array_merge([
            'mac' => $mac, 'macs' => [$mac], 'name' => $name, 'vendor' => null,
            'interfaces' => ['vtnet1_vlan20'], 'first_seen' => self::NOW - 86400,
            'randomised' => false, 'muted' => false,
        ], $extra);
    }

    private function describe(array $raw, array $rows = []): array
    {
        return Events::describe(
            array_merge(['days' => 7, 'since' => self::NOW - 7 * 86400, 'probing' => true, 'sampling' => true], $raw),
            $rows,
            ['vtnet1_vlan20' => 'HOME'],
            self::NOW
        );
    }

    public function testANewDeviceSaysWhereItJoined()
    {
        $report = $this->describe(
            ['new' => [['mac' => 'aa:00:00:00:00:01', 'at' => self::NOW - 86400]]],
            [$this->row('aa:00:00:00:00:01', 'Laptop')]
        );

        $this->assertSame('new', $report['events'][0]['kind']);
        $this->assertSame('Laptop joined HOME', $report['events'][0]['title']);
        $this->assertSame('/ui/lens/device?mac=aa%3A00%3A00%3A00%3A00%3A01', $report['events'][0]['link']);
    }

    public function testARotationIsNotANewDevice()
    {
        /* the folded row existed before this MAC appeared (§4.61) */
        $row = $this->row('e6:00:00:00:00:02', 'Pixel-8', [
            'macs' => ['e6:00:00:00:00:02', 'e6:00:00:00:00:01'],
            'first_seen' => self::NOW - 10 * 86400,
        ]);

        $report = $this->describe(['new' => [['mac' => 'e6:00:00:00:00:02', 'at' => self::NOW - 3600]]], [$row]);

        $this->assertSame('rotated', $report['events'][0]['kind']);
        $this->assertSame('identity', $report['events'][0]['group']);
        $this->assertStringContainsString('2 private addresses by then', $report['events'][0]['detail']);
        $this->assertSame(0, $report['counts']['devices']);
    }

    public function testTheFirstMacOfAFoldedPhoneIsStillNew()
    {
        $row = $this->row('e6:00:00:00:00:02', 'Pixel-8', [
            'macs' => ['e6:00:00:00:00:02', 'e6:00:00:00:00:01'],
            'first_seen' => self::NOW - 3 * 86400,
        ]);

        $report = $this->describe(['new' => [['mac' => 'e6:00:00:00:00:01', 'at' => self::NOW - 3 * 86400]]], [$row]);

        $this->assertSame('new', $report['events'][0]['kind']);
    }

    public function testAGatewayDownDuringAnOutageIsItsReasonNotASecondEvent()
    {
        $report = $this->describe([
            'outages' => [['from' => self::NOW - 7200, 'to' => self::NOW - 5700, 'ongoing' => false]],
            'gateways' => [
                ['name' => 'WAN', 'from' => self::NOW - 7200, 'to' => self::NOW - 5700, 'state' => 'down', 'loss' => 100],
                ['name' => 'WAN', 'from' => self::NOW - 3600, 'to' => self::NOW - 2400, 'state' => 'degraded', 'loss' => 12.5],
            ],
        ]);

        $this->assertSame(['gateway', 'outage'], array_column($report['events'], 'kind'));
        $this->assertSame('WAN was down with it.', $report['events'][1]['detail']);
        $this->assertSame('The internet was unreachable for 25 minutes', $report['events'][1]['title']);
        $this->assertSame('Up to 12.5% of packets lost.', $report['events'][0]['detail']);
        $this->assertSame('notice', $report['events'][0]['tone']);
    }

    public function testAnOngoingOutageIsSaidInThePresent()
    {
        $report = $this->describe(['outages' => [['from' => self::NOW - 600, 'to' => self::NOW, 'ongoing' => true]]]);

        $this->assertStringStartsWith('The internet is unreachable', $report['events'][0]['title']);
        $this->assertSame('alert', $report['events'][0]['tone']);
    }

    public function testAnUnusualDayIsADayAndTodaySaysSoFar()
    {
        $report = $this->describe(['unusual' => [
            ['mac' => 'aa:00:00:00:00:01', 'day' => self::NOW - self::NOW % 86400, 'octets' => 4 * 1024 ** 3,
             'usual' => 1024 ** 3, 'times' => 4.0, 'partial' => true],
        ]], [$this->row('aa:00:00:00:00:01', 'NAS')]);

        $this->assertSame('day', $report['events'][0]['grain']);
        $this->assertSame('NAS has moved 4.0 GB so far today', $report['events'][0]['title']);
        $this->assertSame('4 times its usual day of 1.0 GB; its uploads were ordinary.', $report['events'][0]['detail']);
    }

    public function testAnUploadIsToldAsOne()
    {
        /* the camera streaming out, not the laptop pulling updates (§4.66) */
        $report = $this->describe(['unusual' => [
            ['mac' => 'aa:00:00:00:00:01', 'day' => self::NOW - 86400, 'octets' => 5 * 1024 ** 3,
             'usual' => 4 * 1024 ** 3, 'times' => 1.3, 'direction' => 'sent', 'sent' => 4 * 1024 ** 3,
             'sent_usual' => 512 * 1024 ** 2, 'sent_times' => 8.0, 'partial' => false],
        ]], [$this->row('aa:00:00:00:00:01', 'Garden camera')]);

        $this->assertSame('Garden camera sent 4.0 GB', $report['events'][0]['title']);
        $this->assertSame('8 times its usual upload of 512 MB.', $report['events'][0]['detail']);
    }

    public function testAMutedDeviceFoldsAwayAndIsCounted()
    {
        $report = $this->describe(
            ['new' => [['mac' => 'aa:00:00:00:00:01', 'at' => self::NOW - 3600]]],
            [$this->row('aa:00:00:00:00:01', 'Guest phone', ['muted' => true])]
        );

        $this->assertTrue($report['events'][0]['muted']);
        $this->assertSame(0, $report['counts']['all']);
        $this->assertSame(1, $report['muted']);
        $this->assertSame(
            'Nothing worth telling you in the last 7 days. One more from a device you muted.',
            $report['headline']
        );
    }

    public function testAnOverlapIsMutedOnlyWhenBothDevicesAre()
    {
        $raw = ['overlaps' => [['address' => '10.0.0.5', 'interface' => 'vtnet1_vlan20',
                                'macs' => ['aa:00:00:00:00:01', 'aa:00:00:00:00:02'], 'at' => self::NOW - 60]]];
        $one = $this->describe($raw, [
            $this->row('aa:00:00:00:00:01', 'Plug A', ['muted' => true]),
            $this->row('aa:00:00:00:00:02', 'Plug B'),
        ]);
        $both = $this->describe($raw, [
            $this->row('aa:00:00:00:00:01', 'Plug A', ['muted' => true]),
            $this->row('aa:00:00:00:00:02', 'Plug B', ['muted' => true]),
        ]);

        $this->assertFalse($one['events'][0]['muted']);
        $this->assertTrue($both['events'][0]['muted']);
        $this->assertSame('10.0.0.5 on HOME was held by two devices at once', $one['events'][0]['title']);
    }

    public function testAPauseIsTwoEventsAndAMuteDoesNotHideThem()
    {
        $row = $this->row('a4:77:33:00:00:03', 'TV', ['muted' => true]);
        $report = $this->describe(['since' => self::NOW - 7 * 86400, 'pauses' => [
            ['mac' => 'a4:77:33:00:00:03', 'macs' => ['a4:77:33:00:00:03'], 'started' => self::NOW - 7200,
             'until' => self::NOW - 3600, 'ended' => self::NOW - 3500, 'ended_how' => 'expired'],
        ]], [$row]);

        $this->assertSame(['resumed', 'paused'], array_column($report['events'], 'kind'));
        $this->assertSame("TV's pause ended on time", $report['events'][0]['title']);
        $this->assertSame('TV was paused', $report['events'][1]['title']);
        $this->assertFalse($report['events'][1]['muted']);
        $this->assertSame(2, $report['counts']['devices']);
    }

    public function testAPauseStillRunningHasNoEndAndAnOldStartIsNotRepeated()
    {
        $report = $this->describe(['since' => self::NOW - 86400, 'pauses' => [
            ['mac' => 'a4:77:33:00:00:03', 'macs' => ['a4:77:33:00:00:03'], 'started' => self::NOW - 3 * 86400,
             'until' => null, 'ended' => self::NOW - 60, 'ended_how' => 'outside'],
        ]]);

        $this->assertSame(['resumed'], array_column($report['events'], 'kind'));
        $this->assertStringContainsString('outside Lens', $report['events'][0]['detail']);
    }

    public function testNewestFirst()
    {
        $report = $this->describe([
            'new' => [['mac' => 'aa:00:00:00:00:01', 'at' => self::NOW - 7200]],
            'outages' => [['from' => self::NOW - 600, 'to' => self::NOW - 300, 'ongoing' => false]],
        ], [$this->row('aa:00:00:00:00:01', 'Laptop')]);

        $this->assertSame(['outage', 'new'], array_column($report['events'], 'kind'));
    }

    public function testWhatTheListCannotContainIsSaid()
    {
        $report = $this->describe([
            'probing' => false, 'sampling' => false,
            'watching_since' => self::NOW - 86400, 'new_from' => self::NOW + 86400,
            'baseline' => ['needs_days' => 21],
        ]);

        $this->assertCount(4, $report['notes']);
    }
}
