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


use OPNsense\Lens\Headline;
use PHPUnit\Framework\TestCase;

/**
 * One sentence, for someone who reads only that line.
 *
 * The tests are about order. Every sentence here is true on its own; what can
 * go wrong is saying the calm one while a louder one applies.
 */
class HeadlineTest extends TestCase
{
    private const NOW = 1790000000;

    private function compose(array $summary = [], array $baseline = [], ?int $observed = self::NOW - 60,
                             bool $stale = false): array
    {
        return Headline::compose(
            array_merge(['here' => 12, 'known' => 18, 'moved' => '9.4 GB',
                         'new_yet' => true, 'new' => []], $summary),
            array_merge(['learning' => false, 'days' => 25, 'needs_days' => 21,
                         'unusual' => []], $baseline),
            $observed,
            $stale,
            '24 hours',
            self::NOW
        );
    }

    public function testANormalDayIsSaidCalmly()
    {
        $said = $this->compose();

        $this->assertSame('calm', $said['tone']);
        $this->assertStringContainsString('Everything looks normal', $said['sentence']);
        $this->assertStringContainsString('12 devices home', $said['sentence']);
    }

    private function line(string $state): array
    {
        return ['lines' => [['name' => 'WAN', 'state' => $state, 'delay' => 180.0,
                             'loss' => 12.0, 'monitor' => '1.1.1.1']]];
    }

    public function testNoResolverAnsweringMeansTheInternetIsGone()
    {
        $said = Headline::compose(['here' => 12], ['unusual' => []], self::NOW - 60, false,
                                  '24 hours', self::NOW, $this->line('good'), [
            ['target' => 'Quad9', 'rtt' => null], ['target' => 'Cloudflare', 'rtt' => null],
            ['target' => 'Google', 'rtt' => null],
        ]);

        $this->assertSame('alert', $said['tone']);
        $this->assertStringContainsString('unreachable', $said['sentence']);
        $this->assertStringContainsString('Quad9, Cloudflare, Google', $said['sentence']);
    }

    public function testOneResolverSilentIsNotTheInternetGone()
    {
        $said = Headline::compose(['here' => 12, 'moved' => '1 GB', 'new_yet' => true, 'new' => []],
                                  ['unusual' => [], 'learning' => false], self::NOW - 60, false,
                                  '24 hours', self::NOW, $this->line('good'), [
            ['target' => 'Quad9', 'rtt' => null], ['target' => 'Cloudflare', 'rtt' => 9.0],
        ]);

        $this->assertSame('calm', $said['tone']);
    }

    public function testADownLineIsTheOnlySentence()
    {
        /* live, so it outranks even a stale collector: the line's state is not old */
        $said = Headline::compose(['here' => 12], ['unusual' => []], self::NOW - 9999, true,
                                  '24 hours', self::NOW, $this->line('down'));

        $this->assertSame('alert', $said['tone']);
        $this->assertSame('WAN is down right now.', $said['sentence']);
    }

    public function testAStrugglingLineSaysHowBadly()
    {
        $said = Headline::compose(['here' => 12, 'new_yet' => true, 'new' => ['X']],
                                  ['unusual' => []], self::NOW - 60, false, '24 hours',
                                  self::NOW, $this->line('degraded'));

        $this->assertSame('notice', $said['tone']);
        $this->assertStringContainsString('180 ms', $said['sentence']);
        $this->assertStringContainsString('12% of packets lost', $said['sentence']);
    }

    public function testAHealthyLineChangesNothing()
    {
        $said = Headline::compose(['here' => 12, 'moved' => '1 GB', 'new_yet' => true, 'new' => []],
                                  ['unusual' => [], 'learning' => false], self::NOW - 60, false,
                                  '24 hours', self::NOW, $this->line('good'));

        $this->assertSame('calm', $said['tone']);
    }

    public function testAStoppedCollectorOutranksEverything()
    {
        /* every other sentence would be about the past without saying so */
        $said = $this->compose(
            ['new' => ['Laptop']],
            ['unusual' => [['name' => 'NAS', 'today' => '4 GB', 'times' => 13.3]]],
            self::NOW - 4 * 3600,
            true
        );

        $this->assertSame('alert', $said['tone']);
        $this->assertStringContainsString('last looked', $said['sentence']);
    }

    public function testNeverHavingLookedIsNotReportedAsCalm()
    {
        $this->assertSame('alert', $this->compose([], [], null)['tone']);
    }

    public function testSomethingUnusualOutranksSomethingNew()
    {
        $said = $this->compose(
            ['new' => ['Laptop']],
            ['unusual' => [['name' => 'NAS', 'today' => '4.0 GB', 'times' => 13.3]]]
        );

        $this->assertSame('notice', $said['tone']);
        $this->assertStringContainsString('NAS moved 4.0 GB, 13.3 times', $said['sentence']);
        $this->assertStringContainsString('Laptop', implode(' ', $said['also']),
            'the new device is still mentioned, just second');
    }

    public function testANewDeviceIsNamed()
    {
        $said = $this->compose(['new' => ['Unknown phone']]);

        $this->assertSame('notice', $said['tone']);
        $this->assertStringContainsString('Unknown phone joined', $said['sentence']);
    }

    public function testNewIsNotSaidBeforeLensCanKnowWhatNewMeans()
    {
        /* §4.34: on a fresh install everything is new, which says nothing */
        $said = $this->compose(['new_yet' => false, 'new' => ['A', 'B', 'C']]);

        $this->assertSame('calm', $said['tone']);
    }

    public function testTheLearningStateRidesAlongWithACalmDay()
    {
        $said = $this->compose([], ['learning' => true, 'days' => 9]);

        $this->assertSame('calm', $said['tone']);
        $this->assertStringContainsString('day 9 of 21', implode(' ', $said['also']));
    }

    public function testAWholeMultipleReadsAsAWholeNumber()
    {
        $said = $this->compose([], ['unusual' => [['name' => 'NAS', 'today' => '4 GB', 'times' => 4.0]]]);

        $this->assertStringContainsString(' 4 times', $said['sentence']);
    }
}
