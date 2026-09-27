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
use OPNsense\Lens\Destinations;
use OPNsense\Lens\DnsDevice;
use PHPUnit\Framework\TestCase;

/**
 * What one device looked up, and how much of it Lens could see (§4.64).
 */
class DnsDeviceTest extends TestCase
{
    private const ON = ['enabled' => true, 'stats' => true];

    public function testSwitchedOffPointsToSettings()
    {
        $card = DnsDevice::describe(['enabled' => false], self::ON);

        $this->assertFalse($card['shown']);
        $this->assertTrue($card['settings']);
    }

    public function testNoUnboundIsSaid()
    {
        $card = DnsDevice::describe(['enabled' => true, 'asked' => 1], ['enabled' => false, 'stats' => false]);

        $this->assertStringContainsString('does not resolve with Unbound', $card['note']);
    }

    public function testASampleIsNeverPresentedAsATotal()
    {
        $card = DnsDevice::describe([
            'enabled' => true, 'asked' => 4, 'answered' => 3, 'capped_pieces' => 2,
            'queries' => 1500, 'blocked' => 10,
            'domains' => [['domain' => 'a.example', 'count' => 900, 'blocked' => 0, 'last' => 1],
                          ['domain' => 'ads.example', 'count' => 10, 'blocked' => 10, 'blocklist' => 'ads', 'last' => 2]],
        ], self::ON);

        $this->assertTrue($card['shown']);
        $this->assertTrue($card['rows'][1]['blocked']);
        $this->assertSame('1,500 questions seen, 10 blocked, 2 different names.', $card['summary']);
        $this->assertStringContainsString('2 of the 4 stretches asked had more', $card['note']);
        $this->assertStringContainsString('1 of 4 requests to Unbound did not answer', $card['note']);
    }

    public function testNoAddressInTheRange()
    {
        $this->assertSame('It held no address in this range.', DnsDevice::describe(
            ['enabled' => true, 'asked' => 0],
            self::ON
        )['note']);
    }
}
