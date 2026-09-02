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
use PHPUnit\Framework\TestCase;

/**
 * Observations to devices.
 *
 * Two cases here are not formatting. A device that holds two addresses at once
 * must stay one device -- the operator's admin PC really is in two VLANs, and
 * splitting it would halve its traffic in every later view. And a collector
 * that stopped running must not be reported as an empty network: presence is
 * measured against the last observation, never against the wall clock.
 */
class DeviceReportTest extends TestCase
{
    private const NOW = 1788000000;
    private const OBSERVED = self::NOW - 120;

    private const MACDB = ['42C538' => 'Intel Corporate', 'B827EB' => 'Raspberry Pi Foundation'];

    private function given(array $overrides = []): array
    {
        return array_merge([
            'mac' => '42:c5:38:e1:54:c7',
            'first_seen' => self::NOW - 3 * 86400,
            'last_seen' => self::OBSERVED,
            'randomised' => false,
            'is_local' => false,
            'hostname' => null,
            'hostname_source' => null,
            'addresses' => [[
                'address' => '10.0.10.5',
                'interface' => 'HOME',
                'first_seen' => self::NOW - 3 * 86400,
                'last_seen' => self::OBSERVED,
            ]],
        ], $overrides);
    }

    private function describe(
        array $devices,
        ?int $observedAt = self::OBSERVED,
        array $traffic = []
    ): array {
        return DeviceReport::describe($devices, self::MACDB, $traffic, $observedAt, self::NOW);
    }

    private function one(array $overrides = [], ?int $observedAt = self::OBSERVED): array
    {
        return $this->describe([$this->given($overrides)], $observedAt)['devices'][0];
    }

    // ----------------------------------------------------- one device, one identity

    public function testADeviceInTwoVlansAtOnceIsOneDeviceWithTwoAddresses()
    {
        $device = $this->one(['addresses' => [
            ['address' => '10.0.10.5', 'interface' => 'HOME',
             'first_seen' => self::NOW - 86400, 'last_seen' => self::OBSERVED],
            ['address' => '10.0.99.5', 'interface' => 'MGNT',
             'first_seen' => self::NOW - 86400, 'last_seen' => self::OBSERVED],
        ]]);

        $this->assertCount(2, $device['addresses']);
        $this->assertSame(['HOME', 'MGNT'], $device['interfaces']);
        $this->assertTrue($device['here']);
    }

    public function testAnAddressTheLastObservationDidNotSeeIsMarkedNotCurrent()
    {
        $device = $this->one(['addresses' => [
            ['address' => '10.0.10.5', 'interface' => 'HOME',
             'first_seen' => self::NOW - 86400, 'last_seen' => self::OBSERVED],
            ['address' => '10.0.10.9', 'interface' => 'HOME',
             'first_seen' => self::NOW - 86400, 'last_seen' => self::NOW - 40000],
        ]]);

        $this->assertTrue($device['addresses'][0]['current']);
        $this->assertFalse($device['addresses'][1]['current']);
        $this->assertTrue($device['here'], 'one current address is enough to be here');
    }

    public function testTwoWindowsForTheSameAddressAreOneAddress()
    {
        /*
         * Shipped in 0.3_1 and visible on the operator's router: the observe job
         * had not run for 4.8 hours, so returning devices opened a second window
         * for every address they already held, and the page listed each one
         * twice -- once "here now", once "4.8 hours ago".
         */
        $device = $this->one(['addresses' => [
            ['address' => '10.0.10.5', 'interface' => 'HOME',
             'first_seen' => self::NOW - 86400, 'last_seen' => self::NOW - 17000],
            ['address' => '10.0.10.5', 'interface' => 'HOME',
             'first_seen' => self::OBSERVED, 'last_seen' => self::OBSERVED],
        ]]);

        $this->assertCount(1, $device['addresses']);
        $this->assertSame(2, $device['addresses'][0]['windows']);
        $this->assertTrue($device['addresses'][0]['current']);
        $this->assertSame(self::NOW - 86400, $device['addresses'][0]['first_seen'],
            'the fold keeps the earliest sighting, so "known for" stays true');
    }

    public function testTheSameAddressOnTwoInterfacesStaysTwoEntries()
    {
        $device = $this->one(['addresses' => [
            ['address' => '192.168.178.1', 'interface' => 'igb0',
             'first_seen' => self::OBSERVED, 'last_seen' => self::OBSERVED],
            ['address' => '192.168.178.1', 'interface' => 'igb1',
             'first_seen' => self::OBSERVED, 'last_seen' => self::OBSERVED],
        ]]);

        $this->assertCount(2, $device['addresses']);
    }

    public function testCurrentAddressesAreListedBeforeOnesTheDeviceNoLongerHolds()
    {
        $device = $this->one(['addresses' => [
            ['address' => '10.0.10.99', 'interface' => 'HOME',
             'first_seen' => 0, 'last_seen' => self::NOW - 40000],
            ['address' => '10.0.10.5', 'interface' => 'HOME',
             'first_seen' => 0, 'last_seen' => self::OBSERVED],
        ]]);

        $this->assertSame('10.0.10.5', $device['addresses'][0]['address']);
        $this->assertFalse($device['addresses'][1]['current']);
    }

    public function testTheFirewallsOwnAddressesAreLabelledRatherThanListedAsAClient()
    {
        $this->assertSame('this firewall', $this->one(['is_local' => true])['role']);
        $this->assertNull($this->one()['role']);
    }

    // ----------------------------------------------------- naming, without inventing

    public function testAHostnameWinsAndSaysWhereItCameFrom()
    {
        $device = $this->one(['hostname' => 'admin-pc', 'hostname_source' => 'dnsmasq']);

        $this->assertSame('admin-pc', $device['name']);
        $this->assertStringContainsString('dnsmasq', $device['named_by']);
    }

    public function testWithoutAHostnameTheVendorAndTheMacTailAreUsed()
    {
        $device = $this->one();

        $this->assertSame('Intel Corporate E154C7', $device['name']);
        $this->assertSame('Intel Corporate', $device['vendor']);
    }

    public function testAnUnknownVendorIsNotGuessedAtAndTheMacIsShownInstead()
    {
        $device = $this->one(['mac' => 'aa:bb:cc:dd:ee:ff']);

        $this->assertSame('aa:bb:cc:dd:ee:ff', $device['name']);
        $this->assertNull($device['vendor']);
    }

    public function testARandomisedMacIsLabelledRatherThanQuietlyListed()
    {
        $device = $this->one(['randomised' => true]);

        $this->assertTrue($device['randomised']);
        $this->assertNotNull($device['caveat']);
    }

    // ----------------------------------------------------- the silent failure

    public function testAStaleCollectorIsSaidOutLoudAndNotShownAsAnEmptyNetwork()
    {
        $report = $this->describe([$this->given()], self::NOW - 4 * 3600);

        $this->assertTrue($report['stale']);
        $this->assertNotNull($report['note']);
        $this->assertStringNotContainsString('here now', $report['headline']);
    }

    public function testWhenTheCollectorIsStaleNoDeviceClaimsToBeHereNow()
    {
        $device = $this->one(
            ['addresses' => [[
                'address' => '10.0.10.5', 'interface' => 'HOME',
                'first_seen' => self::NOW - 86400, 'last_seen' => self::NOW - 4 * 3600,
            ]]],
            self::NOW - 3600
        );

        $this->assertFalse($device['here']);
    }

    public function testACollectorThatNeverRanSaysSoInsteadOfReportingNoDevices()
    {
        $report = $this->describe([], null);

        $this->assertTrue($report['stale']);
        $this->assertStringContainsString('has not run', $report['headline']);
    }

    public function testAnEmptyResultAfterARunIsNotBlamedOnTheCollector()
    {
        $report = $this->describe([]);

        $this->assertSame([], $report['devices']);
        $this->assertStringNotContainsString('has not run', $report['headline']);
    }

    // ----------------------------------------------------- the operator's own words

    public function testANameTheOperatorChoseWinsOverEverythingObserved()
    {
        $device = $this->one([
            'hostname' => 'wlan0',
            'hostname_source' => 'dnsmasq',
            'label' => ['name' => 'Kitchen speaker'],
        ]);

        $this->assertSame('Kitchen speaker', $device['name']);
        $this->assertSame('you named it', $device['named_by']);
    }

    public function testAnObservationNeverWritesOverTheOperatorsName()
    {
        /* the whole point of keeping the two apart: dnsmasq renames it, Lens
           does not care */
        $first = $this->one(['hostname' => 'old-name', 'label' => ['name' => 'NAS']]);
        $later = $this->one(['hostname' => 'brand-new-name', 'label' => ['name' => 'NAS']]);

        $this->assertSame('NAS', $first['name']);
        $this->assertSame('NAS', $later['name']);
    }

    public function testAChosenKindOverridesTheGuessAndStopsBeingAGuess()
    {
        $device = $this->one(['label' => ['kind' => 'server']]);

        $this->assertSame('fa-hdd-o', $device['kind']['icon']);
        $this->assertFalse($device['kind']['guessed']);
    }

    public function testAKindThisVersionDoesNotKnowFallsBackRatherThanBlanking()
    {
        $device = $this->one(['label' => ['kind' => 'quantum-toaster']]);

        $this->assertSame('fa-desktop', $device['kind']['icon'], 'Intel Corporate');
    }

    public function testTagsAreSplitTrimmedAndDeduplicated()
    {
        $device = $this->one(['label' => ['tags' => ' hypervisor , production,hypervisor ,, ']]);

        $this->assertSame(['hypervisor', 'production'], $device['tags']);
    }

    public function testANameAndItsTagsAreSearchable()
    {
        $device = $this->one(['label' => ['name' => 'Kitchen speaker', 'tags' => 'living-room']]);

        $this->assertStringContainsString('kitchen speaker', $device['haystack']);
        $this->assertStringContainsString('living-room', $device['haystack']);
    }

    public function testADeviceWithNoLabelReportsEmptyFieldsRatherThanNulls()
    {
        $device = $this->one();

        $this->assertSame(['name' => '', 'kind' => '', 'tags' => '', 'note' => ''], $device['label']);
        $this->assertSame([], $device['tags']);
    }

    // ----------------------------------------------------- traffic on identity

    private const TRAFFIC = [
        'hours' => 24,
        'devices' => [
            '42:c5:38:e1:54:c7' => [
                'in' => ['octets' => 2 * 1024 * 1024, 'packets' => 10],
                'out' => ['octets' => 8 * 1024 * 1024, 'packets' => 40],
            ],
        ],
        'unattributed' => [
            'far_end' => ['octets' => 900 * 1024 * 1024, 'packets' => 1],
            'unknown' => ['octets' => 5 * 1024 * 1024, 'packets' => 1],
            'ambiguous' => ['octets' => 0, 'packets' => 0],
        ],
        'unexplained' => [
            ['reason' => 'unknown', 'interface' => 'GUEST',
             'address' => '10.0.147.9', 'octets' => 4 * 1024 * 1024, 'hours' => 12],
            ['reason' => 'far_end', 'interface' => 'pppoe0',
             'address' => '1.1.1.1', 'octets' => 1024, 'hours' => 1],
            ['reason' => 'unknown', 'interface' => 'GUEST', 'octets' => 99],
        ],
    ];

    public function testADevicesTrafficIsTheSumOfBothDirections()
    {
        $device = $this->describe([$this->given()], self::OBSERVED, self::TRAFFIC)['devices'][0];

        $this->assertSame(10 * 1024 * 1024, $device['octets']);
        $this->assertSame(2 * 1024 * 1024, $device['sent']);
        $this->assertSame(8 * 1024 * 1024, $device['received']);
        $this->assertStringContainsString('10 MB', $device['traffic']);
    }

    public function testADeviceWithNoTrafficIsStillListedAndSaysNothing()
    {
        $device = $this->describe([$this->given()], self::OBSERVED, self::TRAFFIC + [])['devices'][0];
        $quiet = $this->describe([$this->given(['mac' => 'b8:27:eb:00:00:09'])])['devices'][0];

        $this->assertNotNull($device['traffic']);
        $this->assertSame(0, $quiet['octets']);
        $this->assertNull($quiet['traffic']);
    }

    public function testTheHeaviestDeviceLeadsOnceThereIsTrafficToSortBy()
    {
        $report = $this->describe([
            $this->given(['mac' => 'b8:27:eb:00:00:09']),
            $this->given(),
        ], self::OBSERVED, self::TRAFFIC);

        $this->assertSame('42:c5:38:e1:54:c7', $report['devices'][0]['mac']);
    }

    public function testWithoutTrafficTheOrderFallsBackToPresence()
    {
        $report = $this->describe([
            $this->given([
                'mac' => 'b8:27:eb:00:00:09', 'last_seen' => self::NOW - 40000,
                'addresses' => [['address' => '10.0.10.7', 'interface' => 'HOME',
                                 'first_seen' => 0, 'last_seen' => self::NOW - 40000]],
            ]),
            $this->given(),
        ]);

        $this->assertTrue($report['devices'][0]['here']);
    }

    public function testWhatCouldNotBeAttributedIsShownRatherThanDropped()
    {
        $accounting = $this->describe([$this->given()], self::OBSERVED, self::TRAFFIC)['accounting'];

        $this->assertSame('10 MB', $accounting['attributed']);
        $this->assertCount(2, $accounting['rows'], 'ambiguous is zero, so it is not a row');
        $this->assertStringContainsString('900', $accounting['rows'][0]['what']);
        $this->assertStringContainsString('far end', $accounting['rows'][0]['why']);
    }

    public function testAnEmptyTrafficAnswerDoesNotBreakTheDeviceList()
    {
        $report = $this->describe([$this->given()], self::OBSERVED, []);

        $this->assertCount(1, $report['devices']);
        $this->assertSame([], $report['accounting']['rows']);
        $this->assertSame('0 B', $report['accounting']['attributed']);
    }

    public function testTheHeaviestUnattributedAddressesAreNamedNotJustCounted()
    {
        $accounting = $this->describe([$this->given()], self::OBSERVED, self::TRAFFIC)['accounting'];

        $this->assertCount(2, $accounting['unexplained'], 'the row without an address is dropped');
        $this->assertSame('10.0.147.9', $accounting['unexplained'][0]['address']);
        $this->assertSame('GUEST', $accounting['unexplained'][0]['interface']);
        $this->assertSame('4.0 MB', $accounting['unexplained'][0]['what']);
        $this->assertSame(12, $accounting['unexplained'][0]['hours']);
    }

    public function testTheReasonIsShownInWordsRatherThanAsAKey()
    {
        $accounting = $this->describe([$this->given()], self::OBSERVED, self::TRAFFIC)['accounting'];

        $this->assertSame('nobody held it', $accounting['unexplained'][0]['reason']);
        $this->assertSame('far end', $accounting['unexplained'][1]['reason']);
    }

    public function testAReasonFromANewerCollectorIsPassedThroughRatherThanBlanked()
    {
        $accounting = $this->describe([$this->given()], self::OBSERVED, [
            'unexplained' => [['reason' => 'something_later', 'address' => '10.0.0.1',
                               'interface' => 'LAN', 'octets' => 10]],
        ])['accounting'];

        $this->assertSame('something_later', $accounting['unexplained'][0]['reason']);
    }

    // ----------------------------------------------------- grouping the herd

    private function herd(int $count, string $mac_prefix = 'bc:24:11:00:00:'): array
    {
        $devices = [];
        for ($index = 1; $index <= $count; $index++) {
            $devices[] = $this->given([
                'mac' => $mac_prefix . sprintf('%02x', $index),
                'addresses' => [['address' => '10.0.25.' . $index, 'interface' => 'SERVER',
                                 'first_seen' => 0, 'last_seen' => self::OBSERVED]],
            ]);
        }

        return $devices;
    }

    public function testThirtyRowsOfOneVendorBecomeOneGroup()
    {
        /* box 2 lists thirty "Proxmox Server Solutions GmbH" -- one hypervisor's
           worth of virtual NICs, reading as thirty machines */
        $report = DeviceReport::describe(
            $this->herd(30), ['BC2411' => 'Proxmox Server Solutions GmbH'], [],
            self::OBSERVED, self::NOW
        );

        $this->assertCount(1, $report['groups']);
        $this->assertSame('Proxmox Server Solutions GmbH', $report['groups'][0]['label']);
        $this->assertSame(30, $report['groups'][0]['count']);
        $this->assertSame('hardware vendor', $report['groups'][0]['by']);
    }

    public function testTwoOfAKindIsNotAGroup()
    {
        /* collapsing it trades a row you can see for a row you have to open */
        $report = DeviceReport::describe(
            $this->herd(2), ['BC2411' => 'Proxmox Server Solutions GmbH'], [],
            self::OBSERVED, self::NOW
        );

        $this->assertSame([], $report['groups']);
    }

    public function testATagTheOperatorSetBeatsTheVendorItCameWith()
    {
        /* a tag is a statement of intent; a vendor string is an accident of
           procurement */
        $devices = $this->herd(3);
        foreach ($devices as $index => $device) {
            $devices[$index]['label'] = ['tags' => 'hypervisor, production'];
        }

        $report = DeviceReport::describe(
            $devices, ['BC2411' => 'Proxmox Server Solutions GmbH'], [],
            self::OBSERVED, self::NOW
        );

        $this->assertSame('hypervisor', $report['groups'][0]['label']);
        $this->assertSame('your tag', $report['groups'][0]['by']);
    }

    public function testTheFirewallIsNeverFiledUnderItsChipVendor()
    {
        $devices = $this->herd(3);
        $devices[0]['is_local'] = true;

        $report = DeviceReport::describe(
            $devices, ['BC2411' => 'Proxmox Server Solutions GmbH'], [],
            self::OBSERVED, self::NOW
        );

        $ungrouped = array_values(array_filter($report['devices'], function ($device) {
            return $device['group'] === null;
        }));

        $this->assertCount(1, $ungrouped);
        $this->assertTrue($ungrouped[0]['is_local']);
        $this->assertSame([], $report['groups'], 'and the remaining two are not a group');
    }

    public function testADeviceWithNoVendorAndNoTagStaysOnItsOwn()
    {
        $report = $this->describe($this->herd(3, 'ff:ee:dd:00:00:'));

        $this->assertSame([], $report['groups']);
        foreach ($report['devices'] as $device) {
            $this->assertNull($device['group']);
        }
    }

    public function testGroupsAreOfferedHeaviestFirst()
    {
        $devices = array_merge(
            $this->herd(3, 'bc:24:11:00:00:'),
            $this->herd(3, '2c:bc:bb:00:00:')
        );

        $report = DeviceReport::describe($devices, [
            'BC2411' => 'Proxmox Server Solutions GmbH',
            '2CBCBB' => 'Espressif Inc.',
        ], [
            'devices' => ['2c:bc:bb:00:00:01' => ['in' => ['octets' => 9999]]],
        ], self::OBSERVED, self::NOW);

        $this->assertSame('Espressif Inc.', $report['groups'][0]['label']);
    }

    // ----------------------------------------------------- order and robustness

    public function testDevicesThatAreHereComeFirst()
    {
        $report = $this->describe([
            $this->given([
                'mac' => 'b8:27:eb:00:00:01', 'last_seen' => self::NOW - 40000,
                'addresses' => [['address' => '10.0.10.7', 'interface' => 'HOME',
                                 'first_seen' => 0, 'last_seen' => self::NOW - 40000]],
            ]),
            $this->given(),
        ]);

        $this->assertTrue($report['devices'][0]['here']);
        $this->assertFalse($report['devices'][1]['here']);
        $this->assertStringContainsString('1 here now', $report['headline']);
    }

    public function testARowWithoutAMacIsSkippedRatherThanRenderedEmpty()
    {
        $report = $this->describe([['first_seen' => 1], $this->given()]);

        $this->assertCount(1, $report['devices']);
    }

    public function testADeviceWithNoAddressesStillAppears()
    {
        $device = $this->one(['addresses' => []]);

        $this->assertSame([], $device['addresses']);
        $this->assertFalse($device['here']);
    }
}
