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


use OPNsense\Lens\Internet;
use PHPUnit\Framework\TestCase;

/**
 * The panel UniFi opens with.
 */
class InternetTest extends TestCase
{
    private const NOW = 1790000000;

    private function probes(array $latest, array $uptime = []): array
    {
        return ['targets' => ['Quad9', 'Cloudflare', 'Google'], 'latest' => $latest,
                'series' => [], 'uptime' => $uptime, 'step' => 3600, 'since' => self::NOW - 86400];
    }

    private function probe(string $target, ?float $rtt, float $loss = 0.0): array
    {
        return ['target' => $target, 'rtt' => $rtt, 'loss' => $loss];
    }

    public function testTheWanAddressesAreReadAsCoreReadsThem()
    {
        /* element 0 the primary IPv4, element 1 the IPv6 -- OverviewController's own reading */
        $net = Internet::describe(
            ['wan' => [['address' => '93.184.216.34', 'bits' => 32],
                       ['address' => '2003:e8::1', 'bits' => 64]]],
            $this->probes([]), [], 'WAN', self::NOW
        );

        $this->assertSame('93.184.216.34', $net['wan']['ipv4']);
        $this->assertSame('2003:e8::1', $net['wan']['ipv6']);
    }

    public function testAllResolversSilentIsOffline()
    {
        $net = Internet::describe([], $this->probes([
            $this->probe('Quad9', null, 100), $this->probe('Cloudflare', null, 100),
            $this->probe('Google', null, 100),
        ]), [], 'WAN', self::NOW);

        $this->assertSame('down', $net['state']['key']);
    }

    public function testLossOnAnyResolverIsDegradedNotOnline()
    {
        $net = Internet::describe([], $this->probes([
            $this->probe('Quad9', 12.0), $this->probe('Cloudflare', 9.0, 33.3),
            $this->probe('Google', 11.0),
        ]), [], 'WAN', self::NOW);

        $this->assertSame('degraded', $net['state']['key']);
    }

    public function testWithoutProbesTheGatewayDecides()
    {
        $net = Internet::describe([], $this->probes([]), ['worst' => 'down'], 'WAN', self::NOW);

        $this->assertSame('down', $net['state']['key']);
    }

    public function testNothingMeasuredIsUnknownNotOnline()
    {
        $net = Internet::describe([], $this->probes([]), [], 'WAN', self::NOW);

        $this->assertSame('unknown', $net['state']['key']);
    }

    public function testEveryTargetIsListedEvenBeforeItHasAnswered()
    {
        $net = Internet::describe([], $this->probes([$this->probe('Quad9', 12.0)]), [], 'WAN', self::NOW);

        $this->assertSame(['Quad9', 'Cloudflare', 'Google'], array_column($net['probes'], 'target'));
        $this->assertNull($net['probes'][1]['rtt']);
    }

    public function testTheLastOutageIsNamedWithHowLongItLasted()
    {
        $net = Internet::describe([], $this->probes([], [
            'up_pct' => 99.3, 'rounds' => 288, 'strip' => ['up', 'down'],
            'outages' => [[self::NOW - 7200, self::NOW - 6900]],
        ]), [], 'WAN', self::NOW);

        $this->assertSame('5.0 minutes', $net['uptime']['last']['for']);
        $this->assertSame(99.3, $net['uptime']['percent']);
    }
}
