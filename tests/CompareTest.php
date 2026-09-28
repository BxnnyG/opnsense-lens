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
use OPNsense\Lens\Compare;
use PHPUnit\Framework\TestCase;

/**
 * Devices side by side (§4.73).
 */
class CompareTest extends TestCase
{
    private const MB = 1024 * 1024;

    private function entry(string $name, array $points, int $step = 3600): array
    {
        $series = [];
        foreach ($points as $at => $octets) {
            $series[] = ['bucket' => $at, 'sent' => intdiv($octets, 4), 'received' => $octets - intdiv($octets, 4)];
        }

        return [
            'row' => ['mac' => strtolower($name) . ':mac', 'name' => $name, 'here' => true,
                      'kind' => ['icon' => 'fa-desktop'], 'owner' => null],
            'macs' => [strtolower($name) . ':mac'],
            'raw' => ['step' => $step, 'series' => $series],
        ];
    }

    public function testSeriesStartingAtDifferentHoursShareOneAxis()
    {
        $report = Compare::describe([
            $this->entry('A', [3600 => 10, 7200 => 20]),
            $this->entry('B', [7200 => 5, 10800 => 5]),
        ], 24);

        $this->assertSame([3600, 7200, 10800], $report['buckets']);
        $this->assertSame([10, 20, 0], $report['devices'][0]['series']);
        $this->assertSame([0, 5, 5], $report['devices'][1]['series']);
        $this->assertSame(20, $report['peak']);
    }

    public function testTheSlotIsThePlaceAskedNotTheRank()
    {
        $report = Compare::describe([
            $this->entry('Small', [3600 => 1 * self::MB]),
            $this->entry('Large', [3600 => 9 * self::MB]),
        ], 24);

        $this->assertSame([1, 2], array_column($report['devices'], 'slot'));
        $this->assertSame([10, 90], array_column($report['devices'], 'share'));
    }

    public function testTheSentenceNamesTheLargerAndHowManyTimes()
    {
        $report = Compare::describe([
            $this->entry('Phone', [3600 => 2 * self::MB]),
            $this->entry('NAS', [3600 => 7 * self::MB]),
        ], 168);

        $this->assertSame('In the last 7 days, NAS moved 7.0 MB, 3.5 times as much as Phone (2.0 MB).',
                          $report['sentence']);
    }

    public function testNearlyEqualIsSaidAsTheSame()
    {
        $report = Compare::describe([
            $this->entry('A', [3600 => 100 * self::MB]),
            $this->entry('B', [3600 => 105 * self::MB]),
        ], 24);

        $this->assertStringContainsString('moved about the same', $report['sentence']);
    }

    public function testADeviceThatMovedNothingIsSaidSo()
    {
        $report = Compare::describe([
            $this->entry('A', [3600 => 0]),
            $this->entry('B', [3600 => 4 * self::MB]),
        ], 24);

        $this->assertSame('In the last 24 hours, only B moved anything: 4.0 MB.', $report['sentence']);
        $this->assertNull($report['devices'][0]['busiest']);
    }

    public function testOneDeviceAsksForASecond()
    {
        $report = Compare::describe([$this->entry('A', [3600 => 1])], 24);

        $this->assertSame('Choose a second device to compare with.', $report['sentence']);
    }

    public function testADeviceLensNoLongerKnowsIsNamedByItsMac()
    {
        $entry = $this->entry('A', [3600 => 1]);
        $entry['row'] = null;

        $device = Compare::describe([$entry], 24)['devices'][0];

        $this->assertSame('a:mac', $device['name']);
        $this->assertFalse($device['known']);
    }
}
