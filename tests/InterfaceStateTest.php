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
use OPNsense\Lens\InterfaceState;
use PHPUnit\Framework\TestCase;

/**
 * One interface's link, as core's Interfaces: Overview reads it (stage 51).
 */
class InterfaceStateTest extends TestCase
{
    private function details(array $over = []): array
    {
        return array_merge([
            'flags' => ['up', 'broadcast', 'running'], 'status' => 'active',
            'media' => 'Ethernet autoselect (1000baseT <full-duplex>)', 'macaddr' => '00:0d:b9:00:00:01',
            'ipv4' => [['ipaddr' => '10.0.20.1', 'subnetbits' => 24]],
            'ipv6' => [['ipaddr' => 'fe80::1', 'subnetbits' => 64], ['ipaddr' => '2a02:810::1', 'subnetbits' => 64]],
            'vlan' => ['tag' => '20'],
        ], $over);
    }

    public function testAnUpLinkSaysSpeedAddressesAndVlan()
    {
        $link = InterfaceState::describe($this->details(), ['packets received' => '1000', 'input errors' => '0']);

        $this->assertSame('up', $link['status']);
        $this->assertSame('good', $link['tone']);
        $this->assertSame('1 Gbit/s, full duplex', $link['media']);
        $this->assertSame(['10.0.20.1/24', '2a02:810::1/64'], $link['addresses']);
        $this->assertSame(20, $link['vlan']);
        $this->assertSame('no errors since boot', $link['error_text']);
    }

    public function testIfconfigsOwnStatusOutranksTheFlags()
    {
        $link = InterfaceState::describe($this->details(['status' => 'no carrier']), []);
        $this->assertSame('no carrier', $link['status']);
        $this->assertSame('bad', $link['tone']);

        $down = InterfaceState::describe($this->details(['flags' => ['broadcast'], 'status' => '']), []);
        $this->assertSame('down', $down['status']);
    }

    public function testErrorsCountOnlyPastAShareAndAFloor()
    {
        $few = InterfaceState::describe($this->details(), ['packets received' => '10000', 'input errors' => '20']);
        $this->assertSame('good', $few['tone']);
        $this->assertSame('20 errors since boot (0.2% of packets)', $few['error_text']);

        $many = InterfaceState::describe($this->details(),
            ['packets received' => '100000', 'input errors' => '150', 'output errors' => '10', 'collisions' => '0']);
        $this->assertSame('warn', $many['tone']);

        $rare = InterfaceState::describe($this->details(),
            ['packets received' => '90000000', 'input errors' => '500']);
        $this->assertSame('good', $rare['tone']);
    }

    public function testSpeedFromMediaElseFromTheLineRate()
    {
        $this->assertSame('100 Mbit/s, half duplex', InterfaceState::describe(
            $this->details(['media' => 'Ethernet 100baseTX <half-duplex>']), [])['media']);
        $this->assertSame('2.5 Gbit/s', InterfaceState::describe(
            $this->details(['media' => 'Ethernet autoselect', 'is_physical' => true]),
            ['line rate' => '2500000000 bit/s']
        )['media']);
        $this->assertNull(InterfaceState::describe($this->details(['media' => '']), [])['media']);
    }

    public function testTheTileNamesWhatIsNotUp()
    {
        $up = InterfaceState::describe($this->details(), []);
        $gone = InterfaceState::describe($this->details(['status' => 'no carrier']), []);
        $noisy = InterfaceState::describe($this->details(), ['packets received' => '1000', 'input errors' => '200']);

        $this->assertNull(InterfaceState::tile([]));
        $this->assertSame('All 2 interfaces are up.', InterfaceState::tile(['LAN' => $up, 'WAN' => $up])['sentence']);
        $bad = InterfaceState::tile(['LAN' => $up, 'IOT' => $gone]);
        $this->assertSame('bad', $bad['tone']);
        $this->assertSame('IOT: no carrier.', $bad['sentence']);
        $this->assertSame('warn', InterfaceState::tile(['LAN' => $noisy])['tone']);
    }

    public function testWhatTheBoxesShowedOn20261009()
    {
        // router-01's pppoe0: a line rate with no wire behind it
        $pppoe = InterfaceState::describe(['flags' => ['up', 'running'], 'status' => '', 'media' => '',
            'is_physical' => false], ['line rate' => '64000 bit/s']);
        $this->assertNull($pppoe['media']);
        // box-2's GPON on ix0: no carrier, and ifinfo still says 10 Gbit/s
        $dark = InterfaceState::describe(['flags' => ['up'], 'status' => 'no carrier', 'media' => 'Ethernet autoselect',
            'is_physical' => true], ['line rate' => '10000000000 bit/s']);
        $this->assertNull($dark['media']);
        // a VLAN on a lagg: the parent's rate, as core shows it
        $vlan = InterfaceState::describe(['flags' => ['up'], 'status' => 'active', 'media' => 'Ethernet autoselect',
            'vlan' => ['tag' => '24']], ['line rate' => '10000000000 bit/s']);
        $this->assertSame('10 Gbit/s', $vlan['media']);
        // one error in millions is not "0%"
        $one = InterfaceState::describe(['flags' => ['up'], 'status' => 'active'],
            ['packets received' => '48000000', 'input errors' => '1']);
        $this->assertSame('1 error since boot (under 0.001% of packets)', $one['error_text']);
    }
}
