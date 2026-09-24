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


use OPNsense\Lens\Metrics;
use PHPUnit\Framework\TestCase;

/**
 * Prometheus text format. The failures that matter are the ones Prometheus
 * rejects silently or misreads: a label that ends early, and a counter that
 * goes down.
 */
class MetricsTest extends TestCase
{
    private const NOW = 1790000000;

    private function device(array $overrides = []): array
    {
        return array_merge([
            'mac' => 'aa:bb:cc:dd:ee:01', 'name' => 'NAS', 'here' => true,
            'kind' => ['key' => 'server'], 'sent' => 100, 'received' => 400,
        ], $overrides);
    }

    private function render(array $devices = [], array $segments = [], array $status = []): string
    {
        return Metrics::render(
            ['summary' => ['known' => count($devices), 'here' => 1], 'devices' => $devices,
             'baseline' => ['days' => 25, 'needs_days' => 21, 'unusual' => []]],
            ['segments' => $segments],
            $status,
            self::NOW
        );
    }

    public function testADeviceIsExposedWithItsNameAndBothDirections()
    {
        $text = $this->render([$this->device()]);

        $this->assertStringContainsString(
            'lens_device_bytes{mac="aa:bb:cc:dd:ee:01",name="NAS",kind="server",direction="up",window="24h"} 100',
            $text
        );
        $this->assertStringContainsString('direction="down",window="24h"} 400', $text);
        $this->assertStringContainsString('lens_device_present{mac="aa:bb:cc:dd:ee:01",name="NAS",kind="server"} 1', $text);
    }

    public function testEveryValueIsAGaugeBecauseAWindowCanShrink()
    {
        /* a counter that drops as an old hour ages out reads as a reset to rate() */
        $text = $this->render([$this->device()]);

        $this->assertStringNotContainsString(' counter', $text);
        preg_match_all('/^# TYPE (\S+) (\S+)$/m', $text, $types);
        $this->assertSame(array_unique($types[2]), ['gauge']);
    }

    public function testEveryFamilyIsDeclaredBeforeItsSamples()
    {
        $text = $this->render([$this->device()], [], ['runs' => ['observe' => ['at' => self::NOW - 60, 'ok' => true]]]);

        preg_match_all('/^# TYPE (\S+)/m', $text, $declared);
        preg_match_all('/^([a-z_]+)[{ ]/m', $text, $sampled);
        foreach (array_unique($sampled[1]) as $name) {
            $this->assertContains($name, $declared[1], $name . ' has no TYPE line');
        }
    }

    public function testANameWithQuotesAndANewlineCannotBreakTheLine()
    {
        $text = $this->render([$this->device(['name' => "Bennys \"Küche\"\nzwei"])]);

        $this->assertStringContainsString('name="Bennys \\"Küche\\"\\nzwei"', $text);
        foreach (explode("\n", trim($text)) as $line) {
            $this->assertMatchesRegularExpression('/^(#|[a-z_]+[{ ])/', $line);
        }
    }

    public function testTheCollectorAgeIsExposedSoItCanBeAlertedOn()
    {
        $text = $this->render([], [], ['runs' => [
            'observe' => ['at' => self::NOW - 120, 'ok' => true],
            'harvest' => ['at' => self::NOW - 9000, 'ok' => false],
        ]]);

        $this->assertStringContainsString('lens_collector_last_run_seconds{duty="observe",ok="true"} 120', $text);
        $this->assertStringContainsString('lens_collector_last_run_seconds{duty="harvest",ok="false"} 9000', $text);
    }

    public function testOnlyYourOwnNetworksGetANamedRatio()
    {
        $text = $this->render([], [
            ['interface' => 'lagg0_vlan24', 'name' => 'Server', 'is_network' => true,
             'octets' => 1000, 'named_share' => 0.595],
            ['interface' => 'pppoe0', 'name' => 'WAN', 'is_network' => false,
             'octets' => 9000, 'named_share' => 0.0],
        ]);

        $this->assertStringContainsString('lens_segment_named_ratio{interface="lagg0_vlan24",name="Server",yours="true",window="24h"} 0.595', $text);
        $this->assertStringNotContainsString('lens_segment_named_ratio{interface="pppoe0"', $text);
        $this->assertStringContainsString('lens_segment_bytes{interface="pppoe0",name="WAN",yours="false",window="24h"} 9000', $text);
    }

    public function testTheOutputEndsWithANewline()
    {
        $this->assertStringEndsWith("\n", $this->render());
    }
}
