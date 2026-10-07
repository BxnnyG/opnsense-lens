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


use OPNsense\Lens\Wan;
use PHPUnit\Framework\TestCase;

/**
 * Which interface is the internet. Written after the second firewall showed no
 * WAN address and no rate, because its WAN is not keyed `wan`.
 */
class WanTest extends TestCase
{
    private const INTERFACES = [
        'opt18' => ['if' => 'igb0', 'descr' => 'WAN'],
        'opt19' => ['if' => 'igb1', 'descr' => 'ModemDHCP'],
        'lan' => ['if' => 'lagg0_vlan14', 'descr' => 'Home'],
    ];

    public function testEachProtocolFollowsItsOwnDefaultRoute()
    {
        /* the second firewall's routes, as core's get_routes returned them */
        $wan = Wan::pick([
            ['proto' => 'ipv4', 'destination' => 'default', 'netif' => 'igb1'],
            ['proto' => 'ipv6', 'destination' => 'default', 'netif' => 'igb0'],
            ['proto' => 'ipv4', 'destination' => '10.0.14.0/24', 'netif' => 'lagg0_vlan14'],
        ], self::INTERFACES);

        $this->assertSame('opt19', $wan['v4']);
        $this->assertSame('opt18', $wan['v6']);
        $this->assertSame('ModemDHCP', $wan['name']);
    }

    public function testPppoeOnRouterOne()
    {
        $wan = Wan::pick([
            ['proto' => 'ipv4', 'destination' => 'default', 'netif' => 'pppoe0'],
            ['proto' => 'ipv6', 'destination' => 'default', 'netif' => 'pppoe0'],
        ], ['wan' => ['if' => 'pppoe0', 'descr' => 'WAN']]);

        $this->assertSame('wan', $wan['v4']);
        $this->assertSame('wan', $wan['v6']);
    }

    public function testNoDefaultRouteFallsBackToTheConventionalKey()
    {
        $wan = Wan::pick([], ['wan' => ['if' => 'igb0', 'descr' => '']]);

        $this->assertSame('wan', $wan['v4']);
        $this->assertSame('WAN', $wan['name']);
    }
}
