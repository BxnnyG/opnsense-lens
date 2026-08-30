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


use OPNsense\Lens\StoreReport;
use PHPUnit\Framework\TestCase;

/**
 * What the store's own numbers mean.
 *
 * The case that matters most is the silent one: a duty that has stopped running.
 * Nothing else on the page changes when the harvest dies -- the old rows are
 * still there, the charts still draw -- and what it loses meanwhile cannot be
 * collected afterwards.
 */
class StoreReportTest extends TestCase
{
    private const NOW = 1788000000;

    private function given(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'devices' => 13,
            'devices_randomised' => 2,
            'observations' => 41,
            'first_observation' => self::NOW - 3 * 86400,
            'traffic_rows' => 900,
            'first_bucket' => self::NOW - 3 * 86400,
            'last_bucket' => self::NOW - 3600,
            'size_mb' => 1.4,
            'ceiling_mb' => 500,
            'retention_days' => 365,
            'runs' => [
                'observe' => ['at' => self::NOW - 120, 'ok' => true, 'took_ms' => 40, 'detail' => '13 devices'],
                'harvest' => ['at' => self::NOW - 600, 'ok' => true, 'took_ms' => 310, 'detail' => '4 new'],
            ],
        ], $overrides);
    }

    private function rowFor(array $status, string $what): array
    {
        foreach (StoreReport::describe($status, self::NOW)['rows'] as $row) {
            if ($row['what'] === $what) {
                return $row;
            }
        }
        $this->fail('no row ' . $what);
    }

    public function testABoxThatHasNeverCollectedSaysSoRatherThanShowingZeroes(): void
    {
        $report = StoreReport::describe([], self::NOW);

        $this->assertFalse($report['collecting']);
        $this->assertSame([], $report['rows']);
        $this->assertStringContainsString('not been created', $report['headline']);
    }

    public function testYoungHistorySaysHowFarOffABaselineStillIs(): void
    {
        /* an amber verdict in week one would cost S8 its credibility for good,
           so the page counts down instead of pretending */
        $report = StoreReport::describe($this->given(), self::NOW);

        $this->assertTrue($report['collecting']);
        $this->assertStringContainsString('3.0 days', $report['headline']);
        $this->assertStringContainsString('21', $report['headline']);
    }

    public function testGrownUpHistoryStopsCountingDown(): void
    {
        $report = StoreReport::describe(
            $this->given(['first_bucket' => self::NOW - 40 * 86400]),
            self::NOW
        );

        $this->assertStringContainsString('40.0 days', $report['headline']);
        $this->assertStringNotContainsString('baseline needs', $report['headline']);
    }

    public function testNothingHarvestedYetIsExplainedNotBlamed(): void
    {
        $status = $this->given(['traffic_rows' => 0, 'first_bucket' => null]);

        $this->assertStringContainsString(
            'once an hour has fully passed',
            StoreReport::describe($status, self::NOW)['headline']
        );
        $this->assertSame('none yet', $this->rowFor($status, 'Hourly traffic buckets')['detail']);
    }

    public function testRandomisedDevicesAreCountedSeparately(): void
    {
        $detail = $this->rowFor($this->given(), 'Devices known')['detail'];

        $this->assertStringContainsString('13 seen', $detail);
        $this->assertStringContainsString('2 with a randomised', $detail);
    }

    public function testAHealthyRunIsNotFlagged(): void
    {
        $this->assertFalse($this->rowFor($this->given(), 'Last observation')['wrong']);
    }

    public function testAnOverdueObservationIsFlagged(): void
    {
        $status = $this->given();
        $status['runs']['observe']['at'] = self::NOW - 3600;

        $this->assertTrue($this->rowFor($status, 'Last observation')['wrong']);
    }

    public function testAnOverdueHarvestIsFlaggedOnItsOwnLongerClock(): void
    {
        /* the harvest runs every 30 minutes; 20 minutes late is not late */
        $status = $this->given();
        $status['runs']['harvest']['at'] = self::NOW - 3000;
        $this->assertFalse($this->rowFor($status, 'Last harvest')['wrong']);

        $status['runs']['harvest']['at'] = self::NOW - 7200;
        $this->assertTrue($this->rowFor($status, 'Last harvest')['wrong']);
    }

    public function testAFailedRunIsFlaggedEvenWhenItIsRecent(): void
    {
        $status = $this->given();
        $status['runs']['harvest']['ok'] = false;

        $this->assertTrue($this->rowFor($status, 'Last harvest')['wrong']);
    }

    public function testADutyThatHasNeverRunIsFlagged(): void
    {
        $status = $this->given();
        unset($status['runs']['harvest']);

        $row = $this->rowFor($status, 'Last harvest');
        $this->assertTrue($row['wrong']);
        $this->assertSame('has never run', $row['detail']);
    }

    public function testTheCeilingAndRetentionAreShownTogether(): void
    {
        $detail = $this->rowFor($this->given(), 'Database')['detail'];

        $this->assertStringContainsString('1.4 MB', $detail);
        $this->assertStringContainsString('500 MB', $detail);
        $this->assertStringContainsString('365 days', $detail);
    }
}
