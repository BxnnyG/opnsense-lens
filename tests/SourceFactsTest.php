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


use OPNsense\Lens\SourceFacts;
use OPNsense\Lens\SourceReport;
use PHPUnit\Framework\TestCase;

/**
 * Raw configd replies all the way to a verdict.
 *
 * This file exists because of a shipped bug (2026-08-30). SourceReport was
 * correct and fully tested; the controller feeding it never set the key it read,
 * and the page printed the opposite verdict while the whole suite stayed green.
 * Unit tests on both sides cannot catch a gap between them -- only a test that
 * starts where the box starts can.
 *
 * The replies below are the ones the operator's own router produced.
 */
class SourceFactsTest extends TestCase
{
    private const NOW = 1788000060;

    private const INTERFACES = [
        ['key' => 'wan', 'if' => 'pppoe0', 'descr' => ''],
        ['key' => 'lan', 'if' => 'vtnet1_vlan10', 'descr' => 'MGNT'],
        ['key' => 'lo0', 'if' => 'lo0', 'descr' => 'Loopback'],
        ['key' => 'opt3', 'if' => 'vtnet1_vlan20', 'descr' => 'HOME'],
        ['key' => 'LAN_ZONES', 'if' => 'LAN_ZONES', 'descr' => 'LAN_ZONES'],
    ];

    private function raw(array $overrides = []): array
    {
        return array_merge([
            'now' => self::NOW,
            'interfaces' => self::INTERFACES,
            'netflow_capture' => 'wan,lan,opt3',
            'netflow_collect' => '1',
            'netflow_metadata' => file_get_contents(__DIR__ . '/fixtures/netflow-metadata.json'),
            'netflow_collector' => "flowd is running as pid 8554 9195.\n\n\n",
            'netflow_aggregator' => "flowd_aggregate is running as pid 57572.\n\n\n",
            'arp' => file_get_contents(__DIR__ . '/fixtures/arp.json'),
            'dnsmasq_status' => "dnsmasq is running as pid 27444.\n\n\n",
            'dnsmasq_leases' => file_get_contents(__DIR__ . '/fixtures/dnsmasq-leases.json'),
            'unbound_stats' => '1',
            'unbound_status' => "unbound is running as pid 81864.\n\n\n",
            'unbound_mtime' => self::NOW - 39600,
        ], $overrides);
    }

    private function verdictFor(array $raw, string $id): array
    {
        foreach (SourceReport::assess(SourceFacts::assemble($raw)) as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }
        $this->fail('no source ' . $id);
    }

    /**
     * The regression. On 2026-08-30 this box showed "DHCP leases: 10 leases from
     * dnsmasq" and, two rows down, "Unbound is the only resolver here" -- two
     * statements that cannot both be true, produced by a controller that read
     * dnsmasq's state for one row and not the other.
     */
    public function testARunningDnsmasqReachesTheDnsVerdictAndNotOnlyTheDhcpOne(): void
    {
        $facts = SourceFacts::assemble($this->raw());

        $this->assertSame('dnsmasq', $facts['dhcp']['server']);
        $this->assertSame('dnsmasq', $facts['dns']['other_resolver']);

        $dns = $this->verdictFor($this->raw(), 'dns');
        $this->assertSame(SourceReport::ABSENT, $dns['verdict']);
        $this->assertStringContainsString('not a fault', $dns['cause']);
    }

    public function testWithoutDnsmasqTheSameStalenessIsAFault(): void
    {
        $dns = $this->verdictFor($this->raw([
            'dnsmasq_status' => 'dnsmasq is not running.',
            'kea_status' => 'kea is not running.',
        ]), 'dns');

        $this->assertSame(SourceReport::DEGRADED, $dns['verdict']);
    }

    public function testKeaIsUsedWhenDnsmasqIsNotThere(): void
    {
        $facts = SourceFacts::assemble($this->raw([
            'dnsmasq_status' => 'dnsmasq is not running.',
            'kea_status' => 'kea-dhcp4 is running as pid 4242.',
            'kea_leases' => '{"records":[{"address":"10.10.20.5"}]}',
        ]));

        $this->assertSame('Kea', $facts['dhcp']['server']);
        $this->assertSame(1, $facts['dhcp']['leases']);
    }

    public function testNoDhcpServerAtAll(): void
    {
        $facts = SourceFacts::assemble($this->raw([
            'dnsmasq_status' => 'dnsmasq is not running.',
            'kea_status' => '',
        ]));

        $this->assertNull($facts['dhcp']['server']);
    }

    /* ------------------------------------------------------- interfaces */

    public function testLoopbackAndInterfaceGroupsAreNotCaptureCandidates(): void
    {
        /* a group names itself as its own device; neither it nor lo0 is a place
           traffic can be captured, so neither may count as "not captured" */
        $names = SourceFacts::interfaceNames(self::INTERFACES);

        $this->assertSame(['wan' => 'WAN', 'lan' => 'MGNT', 'opt3' => 'HOME'], $names);
    }

    public function testAnInterfaceWithoutADescriptionFallsBackToItsKey(): void
    {
        $this->assertSame('WAN', SourceFacts::interfaceNames(self::INTERFACES)['wan']);
    }

    public function testFullCoverageIsMeasuredAgainstRealInterfacesOnly(): void
    {
        $netflow = $this->verdictFor($this->raw(), 'netflow');

        $this->assertSame(SourceReport::READY, $netflow['verdict']);
        $this->assertSame([], $netflow['missing']);
        $this->assertSame('Capturing all 3 interfaces', $netflow['headline']);
    }

    public function testAMissingInterfaceIsNamedByItsDescription(): void
    {
        $netflow = $this->verdictFor($this->raw(['netflow_capture' => 'wan,lan']), 'netflow');

        $this->assertSame(SourceReport::DEGRADED, $netflow['verdict']);
        $this->assertSame(['HOME'], $netflow['missing']);
    }

    /* ------------------------------------------------------------ misc */

    public function testTheAggregationTimestampSurvivesTheRoundTrip(): void
    {
        $facts = SourceFacts::assemble($this->raw());

        $this->assertSame(1788000030, $facts['netflow']['last_sync']);
        $this->assertSame(SourceReport::READY, $this->verdictFor($this->raw(), 'netflow')['verdict']);
    }

    public function testAnEmptyBoxAssemblesWithoutFatalError(): void
    {
        /* every reply missing: a configd that is not answering at all */
        $facts = SourceFacts::assemble(['now' => self::NOW]);

        $this->assertNull($facts['arp']['entries']);
        $this->assertNull($facts['dhcp']['server']);
        $this->assertFalse($facts['netflow']['collect_enabled']);
        $this->assertCount(4, SourceReport::assess($facts));
    }
}
