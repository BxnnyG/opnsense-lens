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


use OPNsense\Lens\PresenceReport;
use PHPUnit\Framework\TestCase;

/**
 * Who was home, and in which order to show them.
 */
class PresenceReportTest extends TestCase
{
    private const NOW = 1790000000;
    private const DAY = 86400;

    private function row(string $mac, string $name, bool $here = false): array
    {
        return ['mac' => $mac, 'name' => $name, 'here' => $here, 'kind' => ['icon' => 'fa-mobile']];
    }

    private function raw(array $devices, int $start = self::NOW - self::DAY): array
    {
        return ['since' => self::NOW - self::DAY, 'start' => $start, 'now' => self::NOW,
                'devices' => $devices];
    }

    public function testAServerThatNeverLeftIsFoldedAwayFromThePeopleWhoCameHome()
    {
        /* forty solid bars say nothing; the rows that come and go are the answer */
        $report = PresenceReport::describe(
            [$this->row('aa', 'NAS', true), $this->row('bb', 'Phone', true)],
            $this->raw([
                'aa' => ['spans' => [[self::NOW - self::DAY, self::NOW]], 'seconds' => self::DAY],
                'bb' => ['spans' => [[self::NOW - 3600, self::NOW]], 'seconds' => 3600],
            ]),
            self::NOW
        );

        $this->assertSame(['Phone'], array_column($report['moving'], 'name'));
        $this->assertSame(['NAS'], array_column($report['always'], 'name'));
    }

    public function testTheSplitIsMeasuredNotGuessedFromTheKindOfDevice()
    {
        /* a phone that never left the house is "always here" too */
        $report = PresenceReport::describe(
            [$this->row('bb', 'Phone', true)],
            $this->raw(['bb' => ['spans' => [[self::NOW - self::DAY, self::NOW]],
                                 'seconds' => self::DAY]]),
            self::NOW
        );

        $this->assertSame([], $report['moving']);
        $this->assertCount(1, $report['always']);
    }

    public function testDevicesHereNowLeadAndThenTheMostRecentlySeen()
    {
        $report = PresenceReport::describe(
            [$this->row('a', 'Gone early'), $this->row('b', 'Gone late'),
             $this->row('c', 'Home now', true)],
            $this->raw([
                'a' => ['spans' => [[self::NOW - 80000, self::NOW - 70000]], 'seconds' => 10000],
                'b' => ['spans' => [[self::NOW - 20000, self::NOW - 10000]], 'seconds' => 10000],
                'c' => ['spans' => [[self::NOW - 5000, self::NOW]], 'seconds' => 5000],
            ]),
            self::NOW
        );

        $this->assertSame(['Home now', 'Gone late', 'Gone early'],
                          array_column($report['moving'], 'name'));
    }

    public function testADeviceNotSeenInTheWindowIsCountedNotDrawn()
    {
        $report = PresenceReport::describe([$this->row('zz', 'Old laptop')], $this->raw([]), self::NOW);

        $this->assertSame([], $report['moving']);
        $this->assertSame(1, $report['absent']);
    }

    public function testCoverageIsMeasuredAgainstWhenLensStartedWatching()
    {
        /* four hours of watching, present for all four: that is always, not a sixth */
        $start = self::NOW - 4 * 3600;
        $report = PresenceReport::describe(
            [$this->row('aa', 'NAS')],
            $this->raw(['aa' => ['spans' => [[$start, self::NOW]], 'seconds' => 4 * 3600]], $start),
            self::NOW
        );

        $this->assertCount(1, $report['always']);
        $this->assertNotNull($report['note']);
    }
}
