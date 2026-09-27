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
use OPNsense\Lens\Palette;
use PHPUnit\Framework\TestCase;

/**
 * What Ctrl-K can find (§4.65).
 */
class PaletteTest extends TestCase
{
    private function rows(): array
    {
        return [[
            'mac' => 'e6:00:00:00:00:02', 'macs' => ['e6:00:00:00:00:02', 'e6:00:00:00:00:01'],
            'name' => "Anna's phone", 'vendor' => null, 'here' => true,
            'kind' => ['icon' => 'fa-mobile'], 'interfaces' => ['vtnet1_vlan20'],
            'addresses' => [['address' => '10.0.20.62', 'segment' => 'HOME']],
            'haystack' => "e6:00:00:00:00:02 anna's phone 10.0.20.62 vtnet1_vlan20 home",
        ]];
    }

    public function testADeviceIsFoundByAnyMacOfAFoldedPhone()
    {
        $device = Palette::index($this->rows(), ['vtnet1_vlan20' => 'HOME'])['devices'][0];

        $this->assertStringContainsString('e6:00:00:00:00:01', $device['haystack']);
        $this->assertSame('10.0.20.62 · HOME · e6:00:00:00:00:02', $device['sub']);
        $this->assertSame('/ui/lens/device?mac=e6%3A00%3A00%3A00%3A00%3A02', $device['url']);
    }

    public function testOnlyNetworksWithDevicesAreOffered()
    {
        $networks = Palette::index($this->rows(), ['vtnet1_vlan20' => 'HOME', 'vtnet1_vlan99' => 'EMPTY'])['networks'];

        $this->assertSame(['HOME'], array_column($networks, 'name'));
        $this->assertSame('/ui/lens/overview?segment=vtnet1_vlan20', $networks[0]['url']);
    }

    public function testEveryLensPageIsThere()
    {
        $urls = array_column(Palette::index([], [])['pages'], 'url');

        foreach (['dashboard', 'events', 'overview', 'presence', 'segments', 'dns', 'wall', 'preflight', 'settings'] as $page) {
            $this->assertContains('/ui/lens/' . $page, $urls);
        }
    }
}
