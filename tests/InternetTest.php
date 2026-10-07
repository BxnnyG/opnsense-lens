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
    private const WAN = ['v4' => 'wan', 'v6' => 'wan', 'name' => 'WAN'];

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
            $this->probes([]), [], self::WAN, self::NOW
        );

        $this->assertSame('93.184.216.34', $net['wan']['ipv4']);
        $this->assertSame('2003:e8::1', $net['wan']['ipv6']);
    }

    public function testAllResolversSilentIsOffline()
    {
        $net = Internet::describe([], $this->probes([
            $this->probe('Quad9', null, 100), $this->probe('Cloudflare', null, 100),
            $this->probe('Google', null, 100),
        ]), [], self::WAN, self::NOW);

        $this->assertSame('down', $net['state']['key']);
    }

    public function testLossOnAnyResolverIsDegradedNotOnline()
    {
        $net = Internet::describe([], $this->probes([
            $this->probe('Quad9', 12.0), $this->probe('Cloudflare', 9.0, 33.3),
            $this->probe('Google', 11.0),
        ]), [], self::WAN, self::NOW);

        $this->assertSame('degraded', $net['state']['key']);
    }

    public function testWithoutProbesTheGatewayDecides()
    {
        $net = Internet::describe([], $this->probes([]), ['worst' => 'down'], self::WAN, self::NOW);

        $this->assertSame('down', $net['state']['key']);
    }

    public function testNothingMeasuredIsUnknownNotOnline()
    {
        $net = Internet::describe([], $this->probes([]), [], self::WAN, self::NOW);

        $this->assertSame('unknown', $net['state']['key']);
    }

    public function testProbesSwitchedOffAreTheirOwnStateNotOffline()
    {
        /* the collector sends no targets and no latest round once they are off,
           so the last round before the switch cannot read as the internet now */
        $probes = ['probing' => false, 'targets' => [], 'latest' => [], 'series' => [], 'uptime' => []];
        $net = Internet::describe([], $probes, ['worst' => 'good'], self::WAN, self::NOW);

        $this->assertFalse($net['probing']);
        $this->assertSame('up', $net['state']['key']);
        $this->assertSame([], $net['probes']);
    }

    public function testAnOlderCollectorThatSaysNothingIsProbing()
    {
        $net = Internet::describe([], $this->probes([]), [], self::WAN, self::NOW);

        $this->assertTrue($net['probing']);
    }

    public function testEveryTargetIsListedEvenBeforeItHasAnswered()
    {
        $net = Internet::describe([], $this->probes([$this->probe('Quad9', 12.0)]), [], self::WAN, self::NOW);

        $this->assertSame(['Quad9', 'Cloudflare', 'Google'], array_column($net['probes'], 'target'));
        $this->assertNull($net['probes'][1]['rtt']);
    }

    public function testTheLastOutageIsNamedWithHowLongItLasted()
    {
        $net = Internet::describe([], $this->probes([], [
            'up_pct' => 99.3, 'rounds' => 288, 'strip' => ['up', 'down'],
            'outages' => [[self::NOW - 7200, self::NOW - 6900]],
        ]), [], self::WAN, self::NOW);

        $this->assertSame('5.0 minutes', $net['uptime']['last']['for']);
        $this->assertSame(99.3, $net['uptime']['percent']);
    }

    public function testIpv4AndIpv6CanLeaveThroughDifferentInterfaces()
    {
        /* the operator's second firewall: v4 through opt19, v6 through opt18 */
        $net = Internet::describe(
            ['opt19' => [['address' => '192.168.178.32']], 'opt18' => [['address' => '192.168.178.31'],
                                                                         ['address' => '2003:e0::1']]],
            $this->probes([]), [], ['v4' => 'opt19', 'v6' => 'opt18', 'name' => 'ModemDHCP'], self::NOW
        );

        $this->assertSame('192.168.178.32', $net['wan']['ipv4']);
        $this->assertSame('2003:e0::1', $net['wan']['ipv6']);
        $this->assertSame('ModemDHCP', $net['wan']['name']);
    }

    public function testALinkLocalAddressIsNotShownAsTheInternetAddress()
    {
        $net = Internet::describe(['wan' => [['address' => '93.1.2.3'], ['address' => 'fe80::a6bf:1ff:fe2e:e533%igb0']]],
                                  $this->probes([]), [], self::WAN, self::NOW);

        $this->assertNull($net['wan']['ipv6']);
    }
}
