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
use OPNsense\Lens\Wall;
use PHPUnit\Framework\TestCase;

/**
 * What the wall shows, trimmed from what the pages say (§4.71).
 */
class WallTest extends TestCase
{
    private const NOW = 1790000000;

    private function device(string $mac, string $name, int $octets, ?string $group = null): array
    {
        return ['mac' => $mac, 'name' => $name, 'octets' => $octets, 'group' => $group,
                'kind' => ['icon' => 'fa-desktop']];
    }

    private function event(string $kind, int $ago, bool $muted = false, string $grain = 'moment'): array
    {
        return ['kind' => $kind, 'tone' => 'info', 'word' => ucfirst($kind), 'title' => $kind . ' ' . $ago,
                'at' => self::NOW - $ago, 'grain' => $grain, 'muted' => $muted];
    }

    public function testAHerdIsOneRowUnderItsGroupAndQuietDevicesAreLeftOut()
    {
        $rows = Wall::fold([
            $this->device('aa', 'pve-1', 300, 'vendor:Proxmox'),
            $this->device('bb', 'NAS', 500),
            $this->device('cc', 'pve-2', 400, 'vendor:Proxmox'),
            $this->device('dd', 'Printer', 0),
        ], [['key' => 'vendor:Proxmox', 'label' => 'Proxmox', 'count' => 2, 'icon' => 'fa-server']]);

        $this->assertSame(["Proxmox \u{00d7} 2", 'NAS'], array_column($rows, 'name'));
        $this->assertSame([700, 500], array_column($rows, 'octets'));
        $this->assertSame('fa-server', $rows[0]['icon']);
    }

    public function testMutedEventsAreNeitherListedNorCountedAndOnlyTheLastDayCounts()
    {
        $wall = Wall::describe([], ['events' => [
            $this->event('new', 600),
            $this->event('overlap', 1200, true),
            $this->event('outage', 3 * 86400),
        ]], [], [], [], self::NOW);

        $this->assertSame(['new', 'outage'], array_column($wall['events'], 'kind'));
        $this->assertSame(1, $wall['figures']['events']);
        $this->assertSame('10 minutes ago', $wall['events'][0]['ago']);
    }

    public function testAnUnusualDayCarriesNoMomentForTheBoardToAgeFrom()
    {
        $wall = Wall::describe([], ['events' => [$this->event('unusual', 3600, false, 'day')]], [], [], [], self::NOW);

        $this->assertSame('day', $wall['events'][0]['grain']);
        $this->assertSame('', $wall['events'][0]['ago']);
    }

    public function testTheListStopsAtItsLength()
    {
        $events = [];
        for ($i = 0; $i < Wall::EVENTS + 5; $i++) {
            $events[] = $this->event('new', 60 * ($i + 1));
        }

        $this->assertCount(Wall::EVENTS, Wall::describe([], ['events' => $events], [], [], [], self::NOW)['events']);
    }

    public function testBeforeTheLearningDaysThereIsNoNewCountButHowLongLensHasWatched()
    {
        $wall = Wall::describe(['summary' => ['here' => 3, 'known' => 5, 'moved' => '1 GB', 'new_yet' => false,
                                              'new' => [], 'watching_for' => '20 hours']], [], [], [], [], self::NOW);

        $this->assertNull($wall['figures']['new']);
        $this->assertSame('20 hours', $wall['figures']['watching_for']);
        $this->assertNull($wall['figures']['change'], 'no day before to compare with');
    }

    public function testMissingPartsLeaveTheirSlotsEmptyRatherThanFailingTheBoard()
    {
        $wall = Wall::describe([], [], [], [], [], self::NOW);

        $this->assertSame([], $wall['events']);
        $this->assertSame([], $wall['top']);
        $this->assertSame([], $wall['people']);
        $this->assertSame('unknown', $wall['internet']['state']['key']);
        $this->assertSame([], $wall['timeline']['series']);
        $this->assertFalse($wall['stale']);
    }

    public function testPeopleKeepOnlyNameAndWhetherTheyAreHome()
    {
        $wall = Wall::describe([], [], [['name' => 'Anna', 'here' => true, 'spans' => [[1, 2]], 'basis' => 'x']],
                               [], [], self::NOW);

        $this->assertSame([['name' => 'Anna', 'here' => true]], $wall['people']);
    }
}
