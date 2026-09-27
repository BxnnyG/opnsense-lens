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
use OPNsense\Lens\Destinations;
use OPNsense\Lens\Comparison;
use PHPUnit\Framework\TestCase;

/**
 * This range beside the same range a week earlier (§4.66).
 */
class ComparisonTest extends TestCase
{
    public function testADeltaIsSignedAndRounded()
    {
        $this->assertSame('+35%', Comparison::delta(135, 100)['text']);
        $this->assertSame("\u{2212}20%", Comparison::delta(80, 100)['text']);
        $this->assertSame('+2.5%', Comparison::delta(1025, 1000)['text']);
        $this->assertSame('same', Comparison::delta(1004, 1000)['text']);
    }

    public function testNothingLastWeekIsNewNotInfinity()
    {
        $this->assertSame('new', Comparison::delta(5, 0)['text']);
        $this->assertSame('', Comparison::delta(0, 0)['text']);
    }

    public function testAnUnwatchedWeekIsWithheldAndSaysWhy()
    {
        $said = Comparison::describe(['covered' => false], 100, 0, 720);

        $this->assertFalse($said['covered']);
        $this->assertArrayNotHasKey('total', $said);
        $this->assertStringContainsString('against the 30 days before', $said['note']);
    }

    public function testADayIsComparedWithTheSameWeekday()
    {
        $said = Comparison::describe(['covered' => true], 150, 100, 24);

        $this->assertSame('against the same day last week', $said['label']);
        $this->assertSame('+50%', $said['total']['text']);
    }
}
