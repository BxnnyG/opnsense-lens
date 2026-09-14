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


use OPNsense\Lens\Window;
use PHPUnit\Framework\TestCase;

/**
 * What a page asked for, against what the store can answer with.
 *
 * Hard-wiring every page to 24 hours meant this question never came up. The
 * moment a range can be chosen it does, and the wrong answer is the quiet one:
 * showing eleven days under a heading that says thirty.
 */
class WindowTest extends TestCase
{
    private const NOW = 1788000000;
    private const DAY = 86400;

    public function testAnUnknownRangeFallsBackRatherThanBeingHonoured()
    {
        /* the API must not be talked into an arbitrary window by the query
           string; the list of ranges lives here and nowhere else */
        $this->assertSame(24, Window::hours('999999'));
        $this->assertSame(24, Window::hours('abc'));
        $this->assertSame(24, Window::hours(null));
        $this->assertSame(168, Window::hours('168'));
    }

    public function testAWindowTheStoreCanCoverSaysNothing()
    {
        $window = Window::describe(24, self::NOW - 30 * self::DAY, self::NOW);

        $this->assertFalse($window['short']);
        $this->assertNull($window['note']);
    }

    public function testAskingForMoreThanTheStoreHasIsExplainedNotHidden()
    {
        $window = Window::describe(720, self::NOW - 11 * self::DAY, self::NOW);

        $this->assertTrue($window['short']);
        $this->assertStringContainsString('had not started yet', $window['note']);
        $this->assertSame('11 days', $window['covered']);
    }

    public function testAnEmptyStoreSaysItIsEmptyRatherThanShort()
    {
        $window = Window::describe(24, null, self::NOW);

        $this->assertTrue($window['empty']);
        $this->assertStringContainsString('no traffic history', $window['note']);
    }

    public function testLongRangesAreDrawnPerDay()
    {
        $this->assertSame('hour', Window::describe(24, self::NOW, self::NOW)['resolution']);
        $this->assertSame('hour', Window::describe(72, self::NOW, self::NOW)['resolution']);
        $this->assertSame('day', Window::describe(168, self::NOW, self::NOW)['resolution']);
    }

    public function testAnHourOfSlackDoesNotCountAsShort()
    {
        /* the newest bucket is always the hour still being written */
        $window = Window::describe(24, self::NOW - 24 * 3600 + 1800, self::NOW);

        $this->assertFalse($window['short']);
    }
}
