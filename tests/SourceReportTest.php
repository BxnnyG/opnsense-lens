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


use OPNsense\Lens\SourceReport;
use PHPUnit\Framework\TestCase;

/**
 * The verdicts, against fact sets taken from the operator's own box on
 * 2026-08-30. Every case here is one this week actually produced -- which is
 * why they are separate tests rather than a table: each one was a way for the
 * page to be confidently wrong.
 */
class SourceReportTest extends TestCase
{
    private const INTERFACES = [
        'wan' => 'WAN', 'lan' => 'MGNT', 'opt1' => 'WANMGNT', 'opt2' => 'IPMI',
        'opt3' => 'HOME', 'opt4' => 'IOT', 'opt5' => 'GUEST', 'opt6' => 'SERVER',
        'opt7' => 'NAS', 'opt8' => 'LAB', 'opt9' => 'NetBird',
    ];

    private const NOW = 1788000000;

    private function netflow(array $overrides = []): array
    {
        return array_merge([
            'interfaces' => self::INTERFACES,
            'capture_interfaces' => array_keys(self::INTERFACES),
            'collect_enabled' => true,
            'collector_running' => true,
            'aggregator_running' => true,
            'last_sync' => self::NOW - 30,
        ], $overrides);
    }

    private function source(array $facts, string $id): array
    {
        foreach (SourceReport::assess($facts + ['now' => self::NOW]) as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }
        $this->fail('no source ' . $id);
    }

    /* ---------------------------------------------------------- NetFlow */

    public function testFullCoverageAndFreshAggregationIsReady(): void
    {
        $source = $this->source(['netflow' => $this->netflow()], 'netflow');

        $this->assertSame(SourceReport::READY, $source['verdict']);
        $this->assertSame('Capturing all 11 interfaces', $source['headline']);
        $this->assertSame([], $source['missing']);
    }

    public function testPartialCoverageNamesWhatIsInvisible(): void
    {
        /* the operator's real configuration until 2026-08-30: a week of history
           about the three segments nobody asks questions about */
        $source = $this->source([
            'netflow' => $this->netflow(['capture_interfaces' => ['lan', 'wan', 'opt1']]),
        ], 'netflow');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
        $this->assertSame('Capturing 3 of 11 interfaces', $source['headline']);
        $this->assertStringContainsString('HOME', $source['cause']);
        $this->assertStringContainsString('IOT', $source['cause']);
        $this->assertStringContainsString('GUEST', $source['cause']);
        $this->assertNotContains('MGNT', $source['missing']);
    }

    public function testNoCaptureInterfacesIsAbsent(): void
    {
        $source = $this->source([
            'netflow' => $this->netflow(['capture_interfaces' => []]),
        ], 'netflow');

        $this->assertSame(SourceReport::ABSENT, $source['verdict']);
        $this->assertStringContainsString('Capture local', $source['action']);
    }

    public function testCaptureWithoutLocalCollectionKeepsNothing(): void
    {
        $source = $this->source([
            'netflow' => $this->netflow(['collect_enabled' => false]),
        ], 'netflow');

        $this->assertSame(SourceReport::ABSENT, $source['verdict']);
        $this->assertStringContainsString('not stored', $source['cause']);
    }

    public function testStoppedAggregatorIsDegradedAndSaysWhyItCosts(): void
    {
        $source = $this->source([
            'netflow' => $this->netflow(['aggregator_running' => false]),
        ], 'netflow');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
        $this->assertStringContainsString('gone for good', $source['cause']);
    }

    public function testStoppedCollectorIsDegraded(): void
    {
        $source = $this->source([
            'netflow' => $this->netflow(['collector_running' => false]),
        ], 'netflow');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
    }

    public function testStaleAggregationIsDegraded(): void
    {
        $source = $this->source([
            'netflow' => $this->netflow(['last_sync' => self::NOW - 7200]),
        ], 'netflow');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
        $this->assertStringContainsString('120 minutes ago', $source['cause']);
    }

    public function testUnknownServiceStateDoesNotOverrideFreshData(): void
    {
        /* an unparseable status string must not condemn a pipeline that is
           demonstrably working; freshness outranks a status string */
        $source = $this->source([
            'netflow' => $this->netflow(['collector_running' => null, 'aggregator_running' => null]),
        ], 'netflow');

        $this->assertSame(SourceReport::READY, $source['verdict']);
    }

    /* -------------------------------------------------------------- DNS */

    public function testUnboundRunningButNotResolvingIsNotReady(): void
    {
        /* the defect this whole stage exists for: stage 1 printed this green */
        $source = $this->source([
            'dns' => [
                'stats_configured' => true,
                'running' => true,
                'dnsmasq_running' => true,
                'data_mtime' => self::NOW - 36000,
            ],
        ], 'dns');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
        $this->assertSame('Running, but not the resolver', $source['headline']);
        $this->assertStringContainsString('10 hours', $source['cause']);
        $this->assertStringContainsString('dnsmasq', $source['cause']);
    }

    public function testStaleUnboundWithoutDnsmasqDoesNotBlameDnsmasq(): void
    {
        $source = $this->source([
            'dns' => [
                'stats_configured' => true,
                'running' => true,
                'dnsmasq_running' => false,
                'data_mtime' => self::NOW - 36000,
            ],
        ], 'dns');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
        $this->assertStringNotContainsString('dnsmasq', $source['cause']);
    }

    public function testFreshUnboundIsReady(): void
    {
        $source = $this->source([
            'dns' => [
                'stats_configured' => true,
                'running' => true,
                'dnsmasq_running' => false,
                'data_mtime' => self::NOW - 12,
            ],
        ], 'dns');

        $this->assertSame(SourceReport::READY, $source['verdict']);
    }

    public function testStatisticsOffIsAbsentAndWarnsAboutWhatEnablingMeans(): void
    {
        $source = $this->source(['dns' => ['stats_configured' => false]], 'dns');

        $this->assertSame(SourceReport::ABSENT, $source['verdict']);
        $this->assertStringContainsString('every name every device looks up', $source['action']);
    }

    public function testStatisticsOnButResolverStoppedIsAbsent(): void
    {
        $source = $this->source([
            'dns' => ['stats_configured' => true, 'running' => false],
        ], 'dns');

        $this->assertSame(SourceReport::ABSENT, $source['verdict']);
    }

    /* ------------------------------------------------------------- DHCP */

    public function testLeasesFromDnsmasqAreReadyButWarnAboutStaticHosts(): void
    {
        /* six of the operator's thirteen devices cannot name themselves */
        $source = $this->source(['dhcp' => ['server' => 'dnsmasq', 'leases' => 9]], 'dhcp');

        $this->assertSame(SourceReport::READY, $source['verdict']);
        $this->assertSame('9 leases from dnsmasq', $source['headline']);
        $this->assertStringContainsString('Statically configured', $source['cause']);
    }

    public function testRunningServerWithNoLeasesIsDegraded(): void
    {
        $source = $this->source(['dhcp' => ['server' => 'Kea', 'leases' => 0]], 'dhcp');

        $this->assertSame(SourceReport::DEGRADED, $source['verdict']);
    }

    public function testNoDhcpServerIsAbsent(): void
    {
        $source = $this->source(['dhcp' => ['server' => null]], 'dhcp');

        $this->assertSame(SourceReport::ABSENT, $source['verdict']);
    }

    /* --------------------------------------------------------- identity */

    public function testPopulatedAddressTableIsReady(): void
    {
        $source = $this->source(['arp' => ['entries' => 20]], 'identity');

        $this->assertSame(SourceReport::READY, $source['verdict']);
        $this->assertStringContainsString('20 addresses', $source['headline']);
    }

    public function testEmptyAddressTableIsDegradedAndUnreadableIsAbsent(): void
    {
        $this->assertSame(
            SourceReport::DEGRADED,
            $this->source(['arp' => ['entries' => 0]], 'identity')['verdict']
        );
        $this->assertSame(
            SourceReport::ABSENT,
            $this->source(['arp' => ['entries' => null]], 'identity')['verdict']
        );
    }

    /* ----------------------------------------------------------- shared */

    public function testEmptyFactsProduceEverySourceWithoutFatalError(): void
    {
        /* the state a box is in the instant the plugin is installed */
        $sources = SourceReport::assess([]);

        $this->assertCount(4, $sources);
        foreach ($sources as $source) {
            $this->assertContains($source['verdict'], [
                SourceReport::READY, SourceReport::DEGRADED, SourceReport::ABSENT,
            ]);
        }
    }

    public function testEveryVerdictCarriesASentence(): void
    {
        /* a red or amber state with no explanation is a support request waiting
           to happen (VISION, point 3) */
        $sets = [
            [],
            ['netflow' => $this->netflow(), 'arp' => ['entries' => 20],
             'dhcp' => ['server' => 'dnsmasq', 'leases' => 9],
             'dns' => ['stats_configured' => true, 'running' => true, 'data_mtime' => self::NOW]],
            ['netflow' => $this->netflow(['capture_interfaces' => ['lan']]),
             'dns' => ['stats_configured' => true, 'running' => true,
                       'dnsmasq_running' => true, 'data_mtime' => self::NOW - 36000]],
        ];

        foreach ($sets as $index => $facts) {
            foreach (SourceReport::assess($facts + ['now' => self::NOW]) as $source) {
                $where = 'set ' . $index . ', source ' . $source['id'];
                $this->assertNotSame('', $source['label'], $where);
                $this->assertNotSame('', $source['headline'], $where);
                $this->assertNotSame('', $source['cause'], $where);
                $this->assertNotSame('', $source['enables'], $where);
                if ($source['verdict'] === SourceReport::ABSENT) {
                    $this->assertNotSame('', $source['action'], $where . ' (absent needs a way out)');
                }
            }
        }
    }

    public function testRetentionSaysTheUncomfortablePart(): void
    {
        $detail = implode(' ', array_column(SourceReport::retention(), 'detail'));

        $this->assertStringContainsString('ONE HOUR', $detail);
        $this->assertStringContainsString('62 days', $detail);
    }
}
