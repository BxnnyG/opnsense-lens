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


use OPNsense\Lens\Duration;
use PHPUnit\Framework\TestCase;

/**
 * Ages in words.
 *
 * Written after the store page put "16477 seconds ago" on the operator's
 * screen. Exact, and unreadable.
 */
class DurationTest extends TestCase
{
    public function testAFewSecondsIsJustNow()
    {
        $this->assertSame('just now', Duration::ago(4));
        $this->assertSame('just now', Duration::ago(89));
    }

    public function testAClockThatWentBackwardsDoesNotProduceANegativeAge()
    {
        $this->assertSame('just now', Duration::ago(-30));
        $this->assertSame('0 seconds', Duration::span(-30));
    }

    public function testTheUnitIsTheOneAHumanWouldHaveChosen()
    {
        $this->assertSame('5.0 minutes ago', Duration::ago(300));
        $this->assertSame('4.6 hours ago', Duration::ago(16477));
        $this->assertSame('3.0 days ago', Duration::ago(3 * 86400));
        $this->assertSame('4.3 weeks ago', Duration::ago(30 * 86400));
    }

    public function testLargeValuesDropTheDecimalThatNobodyReads()
    {
        $this->assertSame('14 hours ago', Duration::ago(14 * 3600));
    }

    public function testSpanReadsAsALengthNotAsAPastMoment()
    {
        $this->assertSame('3.0 days', Duration::span(3 * 86400));
        $this->assertStringNotContainsString('ago', Duration::span(3 * 86400));
    }
}
