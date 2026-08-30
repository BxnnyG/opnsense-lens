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

    private function describe(array $devices, ?int $observedAt = self::OBSERVED): array
    {
        return DeviceReport::describe($devices, self::MACDB, $observedAt, self::NOW);
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
