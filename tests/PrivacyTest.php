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
use OPNsense\Lens\Privacy;
use PHPUnit\Framework\TestCase;

/**
 * What Lens keeps, and what forgetting a device did (§4.72).
 */
class PrivacyTest extends TestCase
{
    private const NOW = 1790000000;

    private function kept(bool $destinations): array
    {
        return [
            'devices' => ['rows' => 12, 'oldest' => self::NOW - 3 * 86400],
            'windows' => ['rows' => 40, 'oldest' => self::NOW - 3 * 86400],
            'traffic_hours' => ['rows' => 900, 'oldest' => self::NOW - 3 * 86400],
            'destination_days' => ['rows' => 0, 'oldest' => null],
            'labels' => ['rows' => 2, 'oldest' => self::NOW - 86400],
            'owners' => ['rows' => 1, 'oldest' => null],
            'retention_days' => 90,
            'destinations_on' => $destinations,
        ];
    }

    private function kind(array $report, string $key): array
    {
        foreach ($report['kinds'] as $kind) {
            if ($kind['key'] === $key) {
                return $kind;
            }
        }
        $this->fail('no kind ' . $key);
    }

    public function testEachKindSaysHowManyHowOldAndForHowLong()
    {
        $report = Privacy::describe($this->kept(true), self::NOW);

        $traffic = $this->kind($report, 'traffic_hours');
        $this->assertSame(900, $traffic['rows']);
        $this->assertSame('3.0 days ago', $traffic['oldest']);
        $this->assertSame('90 days', $traffic['kept_for']);
        $this->assertStringContainsString('1 of them named after a person', $report['headline']);
    }

    public function testAKindWithNothingInItHasNoAge()
    {
        $this->assertSame('', $this->kind(Privacy::describe($this->kept(true), self::NOW), 'summed_days')['oldest']);
    }

    public function testDestinationsSwitchedOffSayItRatherThanARetention()
    {
        $this->assertSame('switched off', $this->kind(Privacy::describe($this->kept(false), self::NOW),
                                                      'destination_days')['state']);
    }

    public function testCoresCopiesAreNamedWithWhereTheyAreCleared()
    {
        $elsewhere = Privacy::describe($this->kept(true), self::NOW)['elsewhere'];

        $this->assertSame(['/ui/unbound/overview', '/ui/diagnostics/netflow', ''], array_column($elsewhere, 'url'));
    }

    public function testAPreviewSaysWhatWouldGoAndTheDeletionThatItComesBack()
    {
        $counts = ['devices' => 1, 'windows' => 3, 'traffic_hours' => 20, 'destination_days' => 0,
                   'summed_days' => 0, 'labels' => 1];

        $preview = Privacy::forgotten(['dry' => true, 'counts' => $counts]);
        $done = Privacy::forgotten(['dry' => false, 'counts' => $counts]);

        $this->assertSame('Forgetting it deletes its record, 3 address windows, 20 hours of traffic, your note.',
                          $preview['sentence']);
        $this->assertTrue($preview['found']);
        $this->assertStringContainsString('sees it again at the next observation', $done['sentence']);
    }

    public function testAPausedDeviceIsNotForgottenAndSaysWhy()
    {
        $answer = Privacy::forgotten(['dry' => true, 'counts' => ['devices' => 1, 'windows' => 2, 'paused' => 1]]);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['paused']);
        $this->assertSame('This device is paused. Resume it on its page first, then forget it.', $answer['sentence']);
    }

    public function testPastPausesAreAKindKeptAfterTheyEnd()
    {
        $report = Privacy::describe(['retention_days' => 365, 'pauses' => ['rows' => 2, 'oldest' => 1000]], 1000 + 86400);
        $pauses = array_values(array_filter($report['kinds'], function ($kind) {
            return $kind['key'] === 'pauses';
        }))[0];

        $this->assertSame(2, $pauses['rows']);
        $this->assertSame('365 days after it ends', $pauses['kept_for']);
    }

    public function testNothingThereAndNoAnswerAreSaidApart()
    {
        $empty = Privacy::forgotten(['dry' => true, 'counts' => ['devices' => 0, 'windows' => 0]]);

        $this->assertFalse($empty['found']);
        $this->assertTrue($empty['ok']);
        $this->assertFalse(Privacy::forgotten([])['ok']);
    }

    public function testEverythingLensKeepsIsListedWithTheNewestKinds()
    {
        $report = \OPNsense\Lens\Privacy::describe([
            'retention_days' => 365,
            'settled_hours' => ['rows' => 120, 'oldest' => 1789000000],
            'firewall_facts' => ['rows' => 2, 'oldest' => 1789990000],
        ], 1790000000);
        $keys = array_column($report['kinds'], 'key');
        $this->assertContains('settled_hours', $keys);
        $this->assertContains('firewall_facts', $keys);
        $facts = $report['kinds'][array_search('firewall_facts', $keys)];
        $this->assertSame('until the next answer replaces it', $facts['kept_for']);
        $pauses = $report['kinds'][array_search('pauses', $keys)];
        $this->assertStringContainsString('the reason you gave', $pauses['holds']);
    }
}
