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


use OPNsense\Lens\BaselineReport;
use PHPUnit\Framework\TestCase;

/**
 * The sentences a judgement is allowed to say.
 *
 * It states the comparison it made, every time, so a reader who disagrees that
 * 4 GB against a 300 MB median is worth mentioning can see the 300 MB.
 */
class BaselineReportTest extends TestCase
{
    private const MB = 1048576;

    public function testTheLearningStateIsShownRatherThanHidden()
    {
        $report = BaselineReport::describe(['days' => 9, 'needs_days' => 21, 'unusual' => []]);

        $this->assertTrue($report['learning']);
        $this->assertStringContainsString('9 of the 21 days', $report['headline']);
        $this->assertStringContainsString('in the first week', $report['headline']);
    }

    public function testAQuietDaySaysWhatItWasMeasuredAgainst()
    {
        $report = BaselineReport::describe(['days' => 25, 'needs_days' => 21, 'unusual' => []]);

        $this->assertFalse($report['learning']);
        $this->assertStringContainsString('25 days', $report['headline']);
    }

    public function testAVerdictCarriesTheComparisonItMade()
    {
        $report = BaselineReport::describe([
            'days' => 25, 'needs_days' => 21,
            'unusual' => [[
                'mac' => 'aa:bb:cc:dd:ee:01', 'name' => 'NAS',
                'today' => 4000 * self::MB, 'usual' => 300 * self::MB, 'times' => 13.3,
            ]],
        ]);

        $says = $report['unusual'][0]['says'];

        $this->assertStringContainsString('300 MB', $says, 'the reader can disagree');
        $this->assertStringContainsString('13.3 times', $says);
        $this->assertSame('NAS', $report['unusual'][0]['name']);
    }

    public function testAWholeMultipleDoesNotReadAsFourPointZero()
    {
        $report = BaselineReport::describe([
            'days' => 25, 'needs_days' => 21,
            'unusual' => [['mac' => 'aa', 'name' => 'aa', 'today' => 4000 * self::MB,
                           'usual' => 1000 * self::MB, 'times' => 4.0]],
        ]);

        $this->assertStringContainsString('4 times', $report['unusual'][0]['says']);
        $this->assertStringNotContainsString('4.0 times', $report['unusual'][0]['says']);
    }

    public function testAnEmptyAnswerIsALearningStateAndNotAnError()
    {
        $report = BaselineReport::describe([]);

        $this->assertTrue($report['learning']);
        $this->assertSame([], $report['unusual']);
    }
}
