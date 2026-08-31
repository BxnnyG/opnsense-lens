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


use OPNsense\Lens\DeviceType;
use PHPUnit\Framework\TestCase;

/**
 * The one thing on the page that is inferred rather than observed.
 *
 * It is allowed to be wrong; it is not allowed to look confident about being
 * wrong. A vendor string nobody recognises gets a neutral mark, never a
 * plausible icon.
 */
class DeviceTypeTest extends TestCase
{
    public function testTheFirewallIsNotAGuess()
    {
        $kind = DeviceType::of('Proxmox Server Solutions GmbH', null, true);

        $this->assertSame('fa-shield', $kind['icon']);
        $this->assertFalse($kind['guessed']);
    }

    public function testTheHypervisorGuestsThatFillThePageAreRecognised()
    {
        $this->assertSame('fa-server', DeviceType::of('Proxmox Server Solutions GmbH', null, false)['icon']);
    }

    public function testTheOperatorsOwnDevicesLandWhereTheyShould()
    {
        $cases = [
            ['fa-mobile', 'Google, Inc.', 'BXY-Pixel-10'],
            ['fa-mobile', null, 'iPhonevonNeha3'],
            ['fa-wifi', 'Ubiquiti Inc', 'U7Lite'],
            ['fa-wifi', 'Routerboard.com', null],
            ['fa-hdd-o', 'Fujitsu Technology Solutions GmbH', null],
            ['fa-lightbulb-o', 'Tuya Smart Inc.', null],
            ['fa-lightbulb-o', 'Espressif Inc.', null],
            ['fa-desktop', 'ASUSTek COMPUTER INC.', null],
            ['fa-desktop', 'Intel Corporate', null],
        ];

        foreach ($cases as $case) {
            list($icon, $vendor, $hostname) = $case;
            $this->assertSame($icon, DeviceType::of($vendor, $hostname, false)['icon'], (string)$vendor);
        }
    }

    public function testAPrinterIsAPrinterAndNotAComputer()
    {
        /* ordering: the general vendor lists would otherwise swallow it */
        $this->assertSame('fa-print', DeviceType::of('Brother Industries', 'HL-L2350DW', false)['icon']);
    }

    public function testNothingRecognisedProducesANeutralMarkNotAPlausibleGuess()
    {
        $kind = DeviceType::of(null, null, false);

        $this->assertSame('fa-circle-o', $kind['icon']);
        $this->assertFalse($kind['guessed']);
    }

    public function testAnUnknownVendorIsNotForcedIntoTheNearestCategory()
    {
        $this->assertSame('fa-circle-o', DeviceType::of('Some Startup Ltd', 'thing-4', false)['icon']);
    }
}
