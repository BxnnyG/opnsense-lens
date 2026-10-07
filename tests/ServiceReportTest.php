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
use OPNsense\Lens\DnsDevice;
use OPNsense\Lens\DnsReport;
use OPNsense\Lens\ServiceReport;
use PHPUnit\Framework\TestCase;

/**
 * Which services the devices asked for (#44, §4.78): a folded phone is one
 * device, grouped by kind, and the limits are said with it.
 */
class ServiceReportTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['mac' => 'aa:aa:aa:aa:aa:01', 'macs' => ['aa:aa:aa:aa:aa:01', 'ba:aa:aa:aa:aa:01'],
             'name' => 'Phone', 'kind' => ['icon' => 'fa-mobile'], 'interfaces' => ['lan'], 'tags' => ['kids']],
            ['mac' => 'aa:aa:aa:aa:aa:02', 'name' => 'TV', 'kind' => ['icon' => 'fa-television'],
             'interfaces' => ['iot'], 'tags' => []],
        ];
    }

    private function service(string $key, string $name, string $kind, array $askers, int $blocked = 0): array
    {
        return ['service' => $key, 'name' => $name, 'kind' => $kind, 'icon' => 'fa-film', 'blocked' => $blocked,
                'queries' => array_sum(array_column($askers, 'count')), 'askers' => $askers];
    }

    public function testAFoldedPhoneIsOneDevice()
    {
        $report = ServiceReport::network(['matched' => 30, 'total' => 120, 'services' => [
            $this->service('netflix', 'Netflix', 'streaming', [
                ['mac' => 'aa:aa:aa:aa:aa:01', 'address' => null, 'count' => 10],
                ['mac' => 'ba:aa:aa:aa:aa:01', 'address' => null, 'count' => 5],
                ['mac' => 'aa:aa:aa:aa:aa:02', 'address' => null, 'count' => 12],
                ['mac' => null, 'address' => '10.0.0.9', 'count' => 3],
            ]),
        ]], $this->rows());

        $card = $report['groups'][0]['services'][0];
        $this->assertSame(2, $card['devices']);
        $this->assertSame('2 devices, 1 address no device held', $card['sub']);
        $this->assertSame(['Phone', 'TV', '10.0.0.9 (no device then)'], array_column($card['askers'], 'name'));
        $this->assertSame('15', $card['askers'][0]['count']);
        $this->assertSame('/ui/lens/device?mac=aa%3Aaa%3Aaa%3Aaa%3Aaa%3A01', $card['askers'][0]['link']);
        $this->assertNull($card['askers'][2]['link']);
        $this->assertEqualsCanonicalizing(['lan', 'iot'], $card['interfaces']);
        $this->assertSame(['kids'], $card['tags']);
        $this->assertSame('25% of the questions belong to a service Lens knows by name; the rest stay names.',
            $report['coverage']);
    }

    public function testGroupsComeInTheKindsOrderAndPlatformLast()
    {
        $one = [['mac' => 'aa:aa:aa:aa:aa:02', 'address' => null, 'count' => 1]];
        $two = array_merge($one, [['mac' => 'aa:aa:aa:aa:aa:01', 'address' => null, 'count' => 1]]);
        $report = ServiceReport::network(['matched' => 3, 'total' => 3, 'services' => [
            $this->service('apple', 'Apple', 'platform', $two),
            $this->service('steam', 'Steam', 'games', $one),
            $this->service('youtube', 'YouTube', 'streaming', $one),
            $this->service('netflix', 'Netflix', 'streaming', $two),
        ]], $this->rows());

        $this->assertSame(['streaming', 'games', 'platform'], array_column($report['groups'], 'kind'));
        $this->assertSame(['Netflix', 'YouTube'], array_column($report['groups'][0]['services'], 'name'));
        $this->assertArrayNotHasKey('sort', $report['groups'][0]['services'][0]);
    }

    public function testOnlyTheBusiestAskersAreNamed()
    {
        $askers = [];
        for ($i = 1; $i <= 7; $i++) {
            $askers[] = ['mac' => null, 'address' => '10.0.0.' . $i, 'count' => $i];
        }
        $card = ServiceReport::network(['services' => [$this->service('x', 'X', 'social', $askers)]], [])
            ['groups'][0]['services'][0];

        $this->assertCount(ServiceReport::ASKERS, $card['askers']);
        $this->assertSame(3, $card['more']);
        $this->assertSame('7', $card['askers'][0]['count']);
    }

    public function testNothingAskedHasNoCoverageSentence()
    {
        $this->assertSame(['groups' => [], 'coverage' => null], ServiceReport::network([], []));
    }

    public function testTheLimitsAreSaid()
    {
        $this->assertStringContainsString('Asking is not watching', ServiceReport::limits());
        $this->assertStringContainsString('DNS over HTTPS', ServiceReport::limits());
    }

    public function testADevicePageGetsItsChips()
    {
        $report = DnsDevice::describe([
            'enabled' => true, 'source' => 'store', 'asked' => 1, 'queries' => 30, 'blocked' => 0,
            'domains' => [['domain' => 'nflxvideo.net', 'count' => 20, 'blocked' => 0]],
            'services' => [['service' => 'netflix', 'name' => 'Netflix', 'kind' => 'streaming', 'icon' => 'fa-film',
                            'queries' => 20, 'blocked' => 0],
                           ['service' => 'apple', 'name' => 'Apple', 'kind' => 'platform', 'icon' => 'fa-apple',
                            'queries' => 1200, 'blocked' => 3]],
        ], ['enabled' => true, 'stats' => true]);

        $this->assertSame(['Netflix', 'Apple'], array_column($report['services'], 'name'));
        $this->assertSame('1,200', $report['services'][1]['queries']);
        $this->assertTrue($report['services'][1]['platform']);
        $this->assertTrue($report['services'][1]['blocked']);
        $this->assertFalse($report['services'][0]['platform']);
    }

    public function testTheWhoAskedWhatListFoldsAPhonesServices()
    {
        $netflix = ['service' => 'netflix', 'name' => 'Netflix', 'kind' => 'streaming', 'icon' => 'fa-film',
                    'blocked' => 0];
        $report = DnsReport::describe([
            'available' => true, 'source' => 'store', 'hours' => 24,
            'totals' => ['total' => 10, 'blocked' => ['total' => 0, 'pct' => 0]],
            'devices' => [
                ['mac' => 'aa:aa:aa:aa:aa:01', 'queries' => 6, 'blocked' => 0, 'names' => 1, 'domains' => [],
                 'services' => [array_merge($netflix, ['queries' => 6])]],
                ['mac' => 'ba:aa:aa:aa:aa:01', 'queries' => 4, 'blocked' => 0, 'names' => 1, 'domains' => [],
                 'services' => [array_merge($netflix, ['queries' => 4])]],
            ],
        ], $this->rows(), ['enabled' => true, 'stats' => true]);

        $this->assertCount(1, $report['by_device']);
        $this->assertSame([['service' => 'netflix', 'name' => 'Netflix', 'icon' => 'fa-film', 'kind' => 'streaming',
                            'platform' => false, 'queries' => '10', 'blocked' => false]],
            $report['by_device'][0]['services']);
        $this->assertSame(['groups' => [], 'coverage' => null], $report['services']);
    }
}
