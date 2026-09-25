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


use OPNsense\Lens\LineQuality;
use PHPUnit\Framework\TestCase;

/**
 * Is the internet all right. Judged by core, read by Lens.
 */
class LineQualityTest extends TestCase
{
    private function gateway(string $name, string $status, string $delay = '12.3 ms',
                             string $loss = '0.0 %'): array
    {
        return ['name' => $name, 'status' => $status, 'status_translated' => ucfirst($status),
                'delay' => $delay, 'stddev' => '1.1 ms', 'loss' => $loss, 'monitor' => '1.1.1.1'];
    }

    public function testCoresOwnVerdictIsUsedNotAThresholdOfLensis()
    {
        /* 180 ms and "none" means the operator's thresholds allow it: good */
        $line = LineQuality::describe(['WAN' => $this->gateway('WAN', 'none', '180.0 ms')], []);

        $this->assertSame('good', $line['lines'][0]['state']);
        $this->assertSame(180.0, $line['lines'][0]['delay']);
    }

    public function testTheWorstLineComesFirst()
    {
        $line = LineQuality::describe([
            'A' => $this->gateway('A', 'none'),
            'B' => $this->gateway('B', 'down'),
            'C' => $this->gateway('C', 'loss', '20.0 ms', '12.0 %'),
        ], []);

        $this->assertSame(['B', 'C', 'A'], array_column($line['lines'], 'name'));
        $this->assertSame('down', $line['worst']);
    }

    public function testAnUnmonitoredGatewayHasNoReadingRatherThanZeroLatency()
    {
        $line = LineQuality::describe(['WAN' => ['name' => 'WAN', 'status' => 'none',
            'delay' => '~', 'stddev' => '~', 'loss' => '~', 'monitor' => '~']], []);

        $this->assertNull($line['lines'][0]['delay']);
        $this->assertFalse($line['lines'][0]['monitored']);
        $this->assertNull($line['lines'][0]['monitor']);
    }

    public function testHistoryIsAttachedToTheGatewayItBelongsTo()
    {
        $line = LineQuality::describe(
            ['WAN' => $this->gateway('WAN', 'none')],
            ['step' => 3600, 'gateways' => ['WAN' => [['at' => 1, 'delay' => 12.0, 'loss' => 0.0]]]]
        );

        $this->assertCount(1, $line['lines'][0]['series']);
    }

    public function testNoGatewaysIsAnEmptyAnswer()
    {
        $this->assertSame([], LineQuality::describe([], [])['lines']);
    }
}
