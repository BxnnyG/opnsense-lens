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


use OPNsense\Lens\IdentityHealth;
use PHPUnit\Framework\TestCase;

/**
 * Whether MAC-keyed identity is still defensible.
 *
 * §4.17 decided it on ten hours of a quiet Saturday and wrote a date in the
 * roadmap. The date was read four days late. These tests are what the date
 * became.
 */
class IdentityHealthTest extends TestCase
{
    private const NOW = 1788000000;
    private const DAY = 86400;

    private function raw(array $overrides = []): array
    {
        return array_merge([
            'devices' => 20,
            'randomised' => 3,
            'watching_since' => self::NOW - 30 * self::DAY,
            'appeared_this_week' => 0,
            'appeared_this_week_randomised' => 0,
            'fleeting' => 0,
            'overlaps' => 0,
            'reused' => 0,
        ], $overrides);
    }

    private function verdict(array $overrides = []): string
    {
        return IdentityHealth::assess($this->raw($overrides), self::NOW)['verdict'];
    }

    public function testAWeekIsTheEarliestTheQuestionCanBeAnsweredAtAll()
    {
        /* the mistake §4.17 made: a quiet weekend hides what a week of devices
           rejoining shows */
        $this->assertSame('too_early', $this->verdict([
            'watching_since' => self::NOW - 2 * self::DAY,
        ]));
    }

    public function testAnEmptyStoreIsTooEarlyAndNotHealthy()
    {
        $this->assertSame('too_early', $this->verdict(['devices' => 0]));
    }

    public function testASteadyNetworkIsHolding()
    {
        $this->assertSame('holding', $this->verdict());
    }

    public function testOneAddressHeldByTwoDevicesAtOnceIsWorthWatching()
    {
        /* the exact measurement MAC-keying was chosen to protect */
        $this->assertSame('watch', $this->verdict(['overlaps' => 1]));
    }

    public function testAListGrowingByRandomisedShortLivedEntriesIsFragmenting()
    {
        /* the operator's own words: "a device list that grows by ten entries a
           week and quietly lies" */
        $this->assertSame('fragmenting', $this->verdict([
            'devices' => 20,
            'appeared_this_week' => 8,
            'appeared_this_week_randomised' => 7,
            'fleeting' => 9,
        ]));
    }

    public function testRealDevicesArrivingIsNotFragmenting()
    {
        /* eight new machines that all announce stable MACs is a network that
           grew, not an identity model falling apart */
        $this->assertSame('watch', $this->verdict([
            'devices' => 20,
            'appeared_this_week' => 8,
            'appeared_this_week_randomised' => 1,
        ]));
    }

    public function testATinyNetworkNeedsMoreThanTwoNewDevicesToCountAsChurn()
    {
        /* a quarter of four devices is one, and one new phone is not a crisis */
        $this->assertSame('watch', $this->verdict([
            'devices' => 4,
            'appeared_this_week' => 2,
            'appeared_this_week_randomised' => 2,
        ]));
    }

    public function testTheVerdictSaysWhatItMeasuredRatherThanScoringIt()
    {
        $report = IdentityHealth::assess($this->raw(['overlaps' => 2]), self::NOW);

        $this->assertNotEmpty($report['says']);
        $this->assertStringContainsString('2 addresses', implode(' ', $report['says']));
        $this->assertStringContainsString('attributed to neither', implode(' ', $report['says']));
    }

    public function testTheHoldingCaseNamesTheMeasurementThatHeldIt()
    {
        $says = implode(' ', IdentityHealth::assess($this->raw(), self::NOW)['says']);

        $this->assertStringContainsString('two devices at the same time', $says);
        $this->assertStringContainsString('holding', $says);
    }
}
