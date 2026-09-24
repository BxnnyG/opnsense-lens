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


use OPNsense\Lens\DeviceProfile;
use OPNsense\Lens\Heatmap;
use PHPUnit\Framework\TestCase;

/**
 * The device page's two new pictures: its week, and where it has been.
 */
class DeviceProfileTest extends TestCase
{
    private const NOW = 1790000000;
    private const MB = 1048576;

    public function testTheWeekStartsOnMondayWhileSqliteStartsOnSunday()
    {
        /* dow 0 is Sunday in SQLite; it belongs in the last row here */
        $grid = Heatmap::grid([[0, 20, 5 * self::MB], [1, 8, self::MB]]);

        $this->assertSame('Mon', $grid['days'][0]);
        $this->assertGreaterThan(0, $grid['rows'][6][20]['octets'], 'Sunday evening, bottom row');
        $this->assertGreaterThan(0, $grid['rows'][0][8]['octets'], 'Monday morning, top row');
    }

    public function testTheBusiestHourIsTheDarkest()
    {
        $grid = Heatmap::grid([[1, 20, 100 * self::MB], [1, 9, self::MB]]);

        $this->assertSame(Heatmap::LEVELS, $grid['rows'][0][20]['level']);
        $this->assertSame(['day' => 0, 'hour' => 20], $grid['busiest']);
    }

    public function testAQuietHourIsStillVisibleNextToABackup()
    {
        /* a linear scale paints the backup hour dark and everything else blank */
        $grid = Heatmap::grid([[1, 3, 1000 * self::MB], [1, 20, 10 * self::MB]]);

        $this->assertGreaterThanOrEqual(1, $grid['rows'][0][20]['level']);
    }

    public function testAnHourWithNothingIsLevelZeroNotOne()
    {
        $grid = Heatmap::grid([[1, 20, self::MB]]);

        $this->assertSame(0, $grid['rows'][0][19]['level']);
    }

    public function testAnEmptyWeekSaysSo()
    {
        $grid = Heatmap::grid([]);

        $this->assertTrue($grid['empty']);
        $this->assertNull($grid['busiest']);
    }

    private function row(): array
    {
        return ['mac' => 'aa:bb:cc:dd:ee:01', 'first_seen' => self::NOW - 30 * 86400,
                'known_for' => '4.3 weeks', 'addresses' => [['address' => '10.0.0.5']]];
    }

    public function testSixtyVisitsToOneLeaseAreOneLineOfTheStory()
    {
        $windows = [];
        for ($visit = 0; $visit < 60; $visit++) {
            $windows[] = ['address' => '10.0.0.5', 'interface' => 'HOME',
                          'first_seen' => self::NOW - 30 * 86400 + $visit * 3600,
                          'last_seen' => self::NOW - 30 * 86400 + $visit * 3600 + 600];
        }

        $profile = DeviceProfile::describe($this->row(), ['windows' => $windows], self::NOW, self::NOW);

        $this->assertCount(1, $profile['story']);
        $this->assertSame(60, $profile['story'][0]['visits']);
        $this->assertSame(60, $profile['facts']['visits']);
    }

    public function testTheAddressItHoldsNowLeadsTheStory()
    {
        $profile = DeviceProfile::describe($this->row(), ['windows' => [
            ['address' => '10.0.0.9', 'interface' => 'HOME',
             'first_seen' => self::NOW - 20 * 86400, 'last_seen' => self::NOW - 10 * 86400],
            ['address' => '10.0.0.5', 'interface' => 'HOME',
             'first_seen' => self::NOW - 9 * 86400, 'last_seen' => self::NOW],
        ]], self::NOW - 60, self::NOW);

        $this->assertSame('10.0.0.5', $profile['story'][0]['address']);
        $this->assertTrue($profile['story'][0]['current']);
        $this->assertSame('now', $profile['story'][0]['to_text']);
        $this->assertFalse($profile['story'][1]['current']);
    }

    public function testASegmentMoveIsTwoLinesAndTwoSegments()
    {
        $profile = DeviceProfile::describe($this->row(), ['windows' => [
            ['address' => '10.0.0.5', 'interface' => 'HOME', 'first_seen' => 1, 'last_seen' => 2],
            ['address' => '10.0.9.5', 'interface' => 'GUEST', 'first_seen' => 3, 'last_seen' => 4],
        ]], self::NOW, self::NOW);

        $this->assertCount(2, $profile['story']);
        $this->assertSame(2, $profile['facts']['segments']);
    }
}
