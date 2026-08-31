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


use OPNsense\Lens\DeviceDetail;
use PHPUnit\Framework\TestCase;

/**
 * One device's hourly history.
 *
 * The case that matters is the empty hour. A chart drawn only over busy hours
 * makes a device that was quiet all night look like one that was busy all
 * night, and a gap where a zero belongs says "no data" when the truth is
 * "nothing happened".
 */
class DeviceDetailTest extends TestCase
{
    private const NOW = 1788000000;
    private const HOUR = 3600;

    private function raw(array $series, array $overrides = []): array
    {
        return array_merge([
            'mac' => 'aa:bb:cc:dd:ee:01',
            'hours' => 24,
            'series' => $series,
            'sent' => array_sum(array_column($series, 'sent')),
            'received' => array_sum(array_column($series, 'received')),
            'interfaces' => ['HOME'],
        ], $overrides);
    }

    private function point(int $index, int $sent, int $received): array
    {
        return [
            'bucket' => self::NOW - (24 - $index) * self::HOUR,
            'sent' => $sent,
            'received' => $received,
        ];
    }

    public function testAQuietHourIsAZeroAndNotAMissingBar()
    {
        $detail = DeviceDetail::describe($this->raw([
            $this->point(0, 0, 0),
            $this->point(1, 1024, 4096),
            $this->point(2, 0, 0),
        ]), self::NOW);

        $this->assertCount(3, $detail['series']);
        $this->assertSame(0, $detail['series'][0]['total']);
    }

    public function testThePeakIsTheHeaviestHourAndScalesTheChart()
    {
        $detail = DeviceDetail::describe($this->raw([
            $this->point(0, 100, 200),
            $this->point(1, 1000, 2000),
        ]), self::NOW);

        $this->assertSame(3000, $detail['peak']);
        $this->assertSame(3000, $detail['busiest']['total'] ?? $detail['peak']);
    }

    public function testADeviceThatMovedNothingHasNoBusiestHourRatherThanAFakeOne()
    {
        $detail = DeviceDetail::describe($this->raw([
            $this->point(0, 0, 0),
            $this->point(1, 0, 0),
        ]), self::NOW);

        $this->assertNull($detail['busiest']);
        $this->assertSame(0, $detail['peak']);
        $this->assertSame('0 B', $detail['total']);
    }

    public function testAShortHistorySaysWhyRatherThanLookingLikeAQuietDay()
    {
        $detail = DeviceDetail::describe($this->raw(
            [$this->point(0, 10, 10), $this->point(1, 10, 10)],
            ['hours' => 24]
        ), self::NOW);

        $this->assertNotNull($detail['note']);
        $this->assertStringContainsString('only been collecting', $detail['note']);
    }

    public function testAFullWindowSaysNothing()
    {
        $series = [];
        for ($index = 0; $index < 24; $index++) {
            $series[] = $this->point($index, 10, 10);
        }

        $this->assertNull(DeviceDetail::describe($this->raw($series), self::NOW)['note']);
    }

    public function testAnEmptyStoreSaysSoInsteadOfDrawingNothing()
    {
        $detail = DeviceDetail::describe($this->raw([]), self::NOW);

        $this->assertSame([], $detail['series']);
        $this->assertNotNull($detail['note']);
    }

    public function testTheDirectionsSurviveTheRoundTrip()
    {
        $detail = DeviceDetail::describe($this->raw([$this->point(0, 2048, 8192)]), self::NOW);

        $this->assertSame('2.0 KB', $detail['sent']);
        $this->assertSame('8.0 KB', $detail['received']);
        $this->assertSame('10 KB', $detail['total']);
    }
}
