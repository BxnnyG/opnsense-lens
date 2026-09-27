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
use OPNsense\Lens\DeviceReport;
use OPNsense\Lens\IdentityFold;
use OPNsense\Lens\PresenceReport;
use PHPUnit\Framework\TestCase;

/**
 * One phone, not four (§4.61). Every test that says "no" is as important as the
 * one that says "yes": a fold that joins two people's phones is worse than four
 * rows, because it is wrong and looks tidy.
 */
class IdentityFoldTest extends TestCase
{
    private const NOW = 1790000000;
    private const DAY = 86400;

    /** a randomised MAC that held one address for the given days ago */
    private function phone(string $mac, int $fromDaysAgo, int $toDaysAgo, array $overrides = []): array
    {
        return array_merge([
            'mac' => $mac,
            'first_seen' => self::NOW - $fromDaysAgo * self::DAY,
            'last_seen' => self::NOW - $toDaysAgo * self::DAY,
            'randomised' => true,
            'is_local' => false,
            'hostname' => 'Pixel-8',
            'hostname_source' => 'dnsmasq',
            'addresses' => [[
                'address' => '10.10.20.' . substr($mac, -1),
                'interface' => 'vtnet1_vlan20',
                'first_seen' => self::NOW - $fromDaysAgo * self::DAY,
                'last_seen' => self::NOW - $toDaysAgo * self::DAY,
            ]],
        ], $overrides);
    }

    private function rotated(): array
    {
        return [
            $this->phone('e6:00:00:00:00:01', 30, 20),
            $this->phone('e6:00:00:00:00:02', 19, 10),
            $this->phone('e6:00:00:00:00:03', 9, 0),
        ];
    }

    public function testAPhoneThatRotatedTwiceIsOneDevice()
    {
        $groups = IdentityFold::groups($this->rotated());

        $this->assertCount(1, $groups);
        $this->assertSame('e6:00:00:00:00:03', $groups[0]['primary'], 'the newest MAC leads');
        $this->assertSame(['e6:00:00:00:00:03', 'e6:00:00:00:00:02', 'e6:00:00:00:00:01'], $groups[0]['macs']);
    }

    public function testTwoPhonesHomeAtTheSameTimeAreTwoDevicesWhateverTheyAreCalled()
    {
        $groups = IdentityFold::groups([
            $this->phone('e6:00:00:00:00:01', 30, 0),
            $this->phone('e6:00:00:00:00:02', 20, 5),
        ]);

        $this->assertSame([], $groups);
    }

    public function testAGenericNameIsNotEvidence()
    {
        $devices = array_map(function ($device) {
            $device['hostname'] = 'iPhone';
            return $device;
        }, $this->rotated());

        $this->assertSame([], IdentityFold::groups($devices));
    }

    public function testABurnedInMacIsNeverFolded()
    {
        $devices = $this->rotated();
        $devices[0]['randomised'] = false;

        $this->assertSame(['e6:00:00:00:00:03', 'e6:00:00:00:00:02'], IdentityFold::groups($devices)[0]['macs']);
    }

    public function testDifferentNetworksAreNotOnePhone()
    {
        $devices = $this->rotated();
        $devices[1]['addresses'][0]['interface'] = 'vtnet1_vlan22';

        $groups = IdentityFold::groups($devices);
        $this->assertSame(['e6:00:00:00:00:03', 'e6:00:00:00:00:01'], $groups[0]['macs']);
    }

    public function testTheOperatorsTwoNamesKeepThemApart()
    {
        $devices = $this->rotated();
        $devices[0]['label'] = ['name' => "Anna's phone"];
        $devices[2]['label'] = ['name' => "Ben's phone"];

        foreach (IdentityFold::groups($devices) as $group) {
            $this->assertFalse(
                in_array('e6:00:00:00:00:01', $group['macs'], true)
                && in_array('e6:00:00:00:00:03', $group['macs'], true)
            );
        }
    }

    public function testTheFoldedRowSumsTrafficAndIsNotNewOnTheDayItRotated()
    {
        $traffic = ['devices' => [
            'e6:00:00:00:00:01' => ['in' => ['octets' => 100], 'out' => ['octets' => 1000]],
            'e6:00:00:00:00:03' => ['in' => ['octets' => 50], 'out' => ['octets' => 500]],
        ]];
        $devices = $this->rotated();
        $devices[2]['first_seen'] = self::NOW - 3600;
        $devices[2]['addresses'][0]['first_seen'] = self::NOW - 3600;
        $devices[2]['addresses'][0]['last_seen'] = self::NOW;

        $report = DeviceReport::describe($devices, [], $traffic, self::NOW, self::NOW);

        $this->assertCount(1, $report['devices']);
        $row = $report['devices'][0];
        $this->assertSame(1650, $row['octets']);
        $this->assertSame(150, $row['sent']);
        $this->assertSame(self::NOW - 30 * self::DAY, $row['first_seen']);
        $this->assertTrue($row['here']);
        $this->assertCount(3, $row['macs']);
        $this->assertStringContainsString('3 private MAC addresses', $row['folded']);
        $this->assertSame([], $report['summary']['new'], 'a rotation is not a new device');
    }

    public function testTheNameTheOperatorGaveAnyOfThemWins()
    {
        $devices = $this->rotated();
        $devices[0]['label'] = ['name' => "Anna's phone", 'kind' => '', 'tags' => 'anna', 'note' => ''];

        $row = DeviceReport::describe($devices, [], [], self::NOW, self::NOW)['devices'][0];

        $this->assertSame("Anna's phone", $row['name']);
        $this->assertSame('e6:00:00:00:00:03', $row['mac']);
    }

    public function testSwitchedOffEveryMacIsItsOwnRow()
    {
        $report = DeviceReport::describe($this->rotated(), [], [], self::NOW, self::NOW, [], false);

        $this->assertCount(3, $report['devices']);
        $this->assertNull($report['devices'][0]['folded']);
    }

    public function testWhosHomeDrawsEveryMacOfTheRow()
    {
        $row = DeviceReport::describe($this->rotated(), [], [], self::NOW, self::NOW)['devices'][0];
        $raw = ['start' => self::NOW - 30 * self::DAY, 'devices' => [
            'e6:00:00:00:00:01' => ['spans' => [[self::NOW - 30 * self::DAY, self::NOW - 20 * self::DAY]],
                                   'seconds' => 10 * self::DAY],
            'e6:00:00:00:00:03' => ['spans' => [[self::NOW - 9 * self::DAY, self::NOW]], 'seconds' => 9 * self::DAY],
        ]];

        $presence = PresenceReport::describe([$row], $raw, self::NOW);
        $entry = array_merge($presence['moving'], $presence['always'])[0];

        $this->assertCount(2, $entry['spans']);
        $this->assertLessThan($entry['spans'][1][0], $entry['spans'][0][0], 'in time order');
    }

    public function testAListOfMacsIsCheckedBeforeItReachesConfigd()
    {
        $this->assertSame('e6:00:00:00:00:01,e6:00:00:00:00:02',
                          DeviceReport::macList('E6:00:00:00:00:01, e6:00:00:00:00:02'));
        $this->assertNull(DeviceReport::macList('e6:00:00:00:00:01;rm -rf /'));
        $this->assertNull(DeviceReport::macList(''));
        $this->assertNull(DeviceReport::macList(implode(',', array_fill(0, 17, 'e6:00:00:00:00:01'))));
    }
}
