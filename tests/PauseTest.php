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

use OPNsense\Lens\Pause;
use PHPUnit\Framework\TestCase;

/**
 * Pausing a device is the second time Lens writes the firewall's own
 * configuration (§4.74). These tests are mostly about when it must not: the
 * firewall, the device the click came from, and every network a pause may not
 * touch (§4.83).
 */
class PauseTest extends TestCase
{
    private const NAMES = ['vlan0.10' => 'MGNT', 'vlan0.20' => 'HOME', 'vlan0.21' => 'IOT'];

    private function row(string $mac, array $addresses, array $extra = []): array
    {
        return array_merge([
            'mac' => $mac,
            'macs' => [$mac],
            'is_local' => false,
            'addresses' => array_map(function ($a) {
                return ['address' => $a[0], 'interface' => $a[1], 'current' => $a[2] ?? true];
            }, $addresses),
            'interfaces' => array_values(array_unique(array_map(function ($a) {
                return $a[1];
            }, $addresses))),
        ], $extra);
    }

    private function household(): array
    {
        return [
            'firewall' => $this->row('00:0d:b9:00:00:01', [['10.0.20.1', 'vlan0.20']], ['is_local' => true]),
            'laptop' => $this->row('3c:22:fb:00:00:02', [['10.0.20.50', 'vlan0.20'], ['fd00::50', 'vlan0.20']]),
            'tv' => $this->row('a4:77:33:00:00:03', [['10.0.21.30', 'vlan0.21']]),
            'admin' => $this->row('42:c5:38:e1:54:c7', [['10.0.10.5', 'vlan0.10'], ['10.0.20.5', 'vlan0.20']]),
            'phone' => $this->row('9a:11:22:00:00:04', [['10.0.20.77', 'vlan0.20']], [
                'macs' => ['9a:11:22:00:00:04', 'de:ad:be:00:00:05'],
            ]),
        ];
    }

    public function testTheFirewallIsNeverPaused()
    {
        $rows = $this->household();
        $this->assertSame(
            'This is the firewall itself.',
            Pause::refuse($rows['firewall'], array_values($rows), '10.0.20.50', ['vlan0.10'], self::NAMES)
        );
    }

    public function testTheDeviceTheClickComesFromIsNeverPaused()
    {
        $rows = $this->household();
        $this->assertSame(
            'This is the device you are using right now.',
            Pause::refuse($rows['laptop'], array_values($rows), '10.0.20.50', ['vlan0.10'], self::NAMES)
        );
        /* by its IPv6 address, spelled another way */
        $this->assertSame(
            'This is the device you are using right now.',
            Pause::refuse($rows['laptop'], array_values($rows), 'fd00:0:0::50', ['vlan0.10'], self::NAMES)
        );
    }

    public function testAFoldedPhoneIsTheClickingDeviceOnAnyOfItsMacs()
    {
        $rows = $this->household();
        $other = $this->row('de:ad:be:00:00:05', [['10.0.20.78', 'vlan0.20']]);
        $this->assertSame(
            'This is the device you are using right now.',
            Pause::refuse($rows['phone'], array_merge(array_values($rows), [$other]), '10.0.20.78', ['vlan0.10'], self::NAMES)
        );
    }

    public function testATickedNetworkProtectsEveryDeviceThatHasBeenOnIt()
    {
        $rows = $this->household();
        /* the admin PC is on HOME as well, and the alias would follow it */
        $this->assertSame(
            'It has been on MGNT, which a pause never touches (Services: Lens: Settings).',
            Pause::refuse($rows['admin'], array_values($rows), '10.0.20.50', ['vlan0.10'], self::NAMES)
        );
    }

    public function testAnAddressHeldBeforeCountsToo()
    {
        $rows = $this->household();
        $rows['tv'] = $this->row('a4:77:33:00:00:03', [['10.0.21.30', 'vlan0.21'], ['10.0.10.99', 'vlan0.10', false]]);
        $this->assertNotNull(Pause::refuse($rows['tv'], array_values($rows), '10.0.20.50', ['vlan0.10'], self::NAMES));
    }

    public function testWithNothingTickedTheClickNetworkIsProtected()
    {
        $rows = $this->household();
        $this->assertSame(
            'It has been on HOME, the network you are clicking from.',
            Pause::refuse($rows['phone'], array_values($rows), '10.0.20.50', [], self::NAMES)
        );
        $this->assertNull(Pause::refuse($rows['tv'], array_values($rows), '10.0.20.50', [], self::NAMES));
    }

    public function testWithNothingTickedAnUnknownClickNetworkRefusesEverything()
    {
        $rows = $this->household();
        /* a VPN address no device on the segments holds */
        $refusal = Pause::refuse($rows['tv'], array_values($rows), '100.64.0.9', [], self::NAMES);
        $this->assertStringStartsWith('Lens cannot tell which network you are clicking from.', $refusal);
    }

    public function testATickedListMeansTheClickNetworkIsNotGuessed()
    {
        $rows = $this->household();
        $this->assertNull(Pause::refuse($rows['tv'], array_values($rows), '100.64.0.9', ['vlan0.10'], self::NAMES));
        /* HOME is not ticked, so a phone on HOME may be paused from HOME */
        $this->assertNull(Pause::refuse($rows['phone'], array_values($rows), '10.0.20.50', ['vlan0.10'], self::NAMES));
    }

    public function testADeviceWithoutAMacCannotBePaused()
    {
        $row = ['mac' => '', 'macs' => [], 'addresses' => [], 'interfaces' => []];
        $this->assertSame(
            'Lens knows this device by an address only, and a pause needs its MAC.',
            Pause::refuse($row, [], '10.0.20.50', ['vlan0.10'])
        );
    }

    public function testDurations()
    {
        $this->assertSame(0, Pause::minutes('0'));
        $this->assertSame(60, Pause::minutes(' 60 '));
        $this->assertSame(10080, Pause::minutes('10080'));
        $this->assertNull(Pause::minutes('10081'));
        $this->assertNull(Pause::minutes('-5'));
        $this->assertNull(Pause::minutes('1e3'));
        $this->assertNull(Pause::minutes(''));
        $this->assertNull(Pause::until(0, 1000));
        $this->assertSame(1000 + 3600, Pause::until(60, 1000));
    }

    public function testAddingKeepsEveryoneElseAndNeverDuplicates()
    {
        $content = "a4:77:33:00:00:03\n9A:11:22:00:00:04";
        $this->assertSame(
            "a4:77:33:00:00:03\n9a:11:22:00:00:04\nde:ad:be:00:00:05",
            Pause::contentWith($content, ['9a:11:22:00:00:04', 'de:ad:be:00:00:05'])
        );
        $this->assertSame('a4:77:33:00:00:03', Pause::contentWith('', ['A4:77:33:00:00:03']));
    }

    public function testRemovingTakesOnlyThatDevice()
    {
        $content = "a4:77:33:00:00:03\n9a:11:22:00:00:04\nde:ad:be:00:00:05";
        $this->assertSame('a4:77:33:00:00:03', Pause::contentWithout($content, ['9a:11:22:00:00:04', 'DE:AD:BE:00:00:05']));
        $this->assertSame('', Pause::contentWithout('a4:77:33:00:00:03', ['a4:77:33:00:00:03']));
        $this->assertSame([], Pause::macsIn(''));
    }

    public function testTheAliasAndTheRuleAreWhatTheDecisionSays()
    {
        $alias = Pause::aliasFields("a4:77:33:00:00:03");
        $this->assertSame('lens_paused', $alias['name']);
        $this->assertSame('mac', $alias['type']);

        $rule = Pause::ruleFields(7);
        $this->assertSame('block', $rule['action']);
        $this->assertSame('1', $rule['quick']);
        $this->assertSame('', $rule['interface'], 'floating, on every interface');
        $this->assertSame('in', $rule['direction']);
        $this->assertSame('inet46', $rule['ipprotocol'], 'IPv6 is not optional (edge case 7)');
        $this->assertSame('lens_paused', $rule['source_net']);
        $this->assertSame('any', $rule['destination_net']);
        $this->assertSame('Lens: paused devices', $rule['description']);
        $this->assertSame('7', $rule['sequence']);
    }

    public function testLensFindsItsRuleByWhatItDoes()
    {
        $rules = [
            'u1' => ['action' => 'pass', 'source_net' => 'lens_paused', 'description' => 'Lens: paused devices'],
            'u2' => ['action' => 'block', 'source_net' => 'any', 'description' => 'Lens: paused devices'],
            'u3' => ['action' => 'block', 'source_net' => 'guests,lens_paused', 'description' => 'kids, renamed'],
        ];
        $this->assertSame('u3', Pause::findRule($rules));
        $this->assertNull(Pause::findRule(['u1' => $rules['u1'], 'u2' => $rules['u2']]));
    }

    public function testANewRuleGoesFirstAmongTheFloatingOnesAndMovesNobody()
    {
        $this->assertSame(1, Pause::firstSequence([]));
        $this->assertSame(9, Pause::firstSequence([
            ['interface' => '', 'sequence' => '10'],
            ['interface' => 'lan', 'sequence' => '2'],
            ['interface' => 'lan,opt1', 'sequence' => '30'],
        ]));
        $this->assertSame(1, Pause::firstSequence([['interface' => '', 'sequence' => '1']]));
        $this->assertSame(4, Pause::firstSequence([['interface' => 'lan', 'interfacenot' => '1', 'sequence' => '5']]));
    }

    public function testTheAliasDecidesWhoIsPaused()
    {
        $open = [
            ['mac' => '9a:11:22:00:00:04', 'macs' => ['9a:11:22:00:00:04', 'de:ad:be:00:00:05'], 'started' => 100, 'until' => 900],
            ['mac' => 'a4:77:33:00:00:03', 'macs' => ['a4:77:33:00:00:03'], 'started' => 50, 'until' => null],
        ];
        /* the TV was taken out of the alias by hand; someone put a MAC in it by hand */
        $status = Pause::status(['9a:11:22:00:00:04', 'de:ad:be:00:00:05', '11:22:33:44:55:66'], $open, 'ok');

        $this->assertSame(['a4:77:33:00:00:03'], $status['stale']);
        $this->assertSame('lens', $status['paused']['de:ad:be:00:00:05']['by']);
        $this->assertSame(900, $status['paused']['de:ad:be:00:00:05']['until']);
        $this->assertSame('alias', $status['paused']['11:22:33:44:55:66']['by']);
        $this->assertArrayNotHasKey('a4:77:33:00:00:03', $status['paused']);
        $this->assertTrue($status['effective']);

        $phone = $this->household()['phone'];
        $this->assertSame(100, Pause::ofDevice($phone, $status)['since']);
        $this->assertNull(Pause::ofDevice($this->household()['tv'], $status));
    }

    public function testMacsInTheAliasBlockNothingWithoutTheRule()
    {
        $this->assertFalse(Pause::status(['a4:77:33:00:00:03'], [], 'disabled')['effective']);
        $this->assertFalse(Pause::status(['a4:77:33:00:00:03'], [], null)['effective']);
        $this->assertFalse(Pause::ofDevice($this->household()['tv'], Pause::status(['a4:77:33:00:00:03'], [], null))['effective']);
    }

    public function testResumingTakesEveryMacThePauseWasGiven()
    {
        /* paused folded as two MACs; today the device folds a third and not the second */
        $open = [
            ['mac' => '9a:11:22:00:00:04', 'macs' => ['9a:11:22:00:00:04', 'de:ad:be:00:00:05']],
            ['mac' => 'a4:77:33:00:00:03', 'macs' => ['a4:77:33:00:00:03']],
        ];
        $target = $this->row('9a:11:22:00:00:04', [], ['macs' => ['9a:11:22:00:00:04', 'fe:00:00:00:00:06']]);

        $set = Pause::resumeSet(['9A:11:22:00:00:04'], $target, $open);

        $this->assertSame(['9a:11:22:00:00:04', 'fe:00:00:00:00:06', 'de:ad:be:00:00:05'], $set['macs']);
        $this->assertSame(['9a:11:22:00:00:04'], $set['keys']);
        $this->assertSame(['macs' => ['11:22:33:44:55:66'], 'keys' => []], Pause::resumeSet(['11:22:33:44:55:66'], null, $open));
    }

    public function testOnlyPausesWhoseTimeHasComeAreDue()
    {
        $open = [
            ['mac' => 'a', 'until' => 1000],
            ['mac' => 'b', 'until' => 1001],
            ['mac' => 'c', 'until' => null],
        ];
        $this->assertSame(['a'], Pause::due($open, 1000));
        $this->assertSame(['a', 'b'], Pause::due($open, 5000));
    }

    public function testTheAliasAndTheRuleCarryTheCategoryLens()
    {
        $this->assertSame('cat-1', Pause::aliasFields('a4:77:33:00:00:03', 'cat-1')['categories']);
        $this->assertSame('cat-1', Pause::ruleFields(7, 'cat-1')['categories']);
        // without a category (core refused it) the pause still goes ahead, unlabelled
        $this->assertArrayNotHasKey('categories', Pause::ruleFields(7));
        $this->assertSame(['name' => 'Lens', 'color' => 'd94f00', 'auto' => '0'], Pause::categoryFields());
    }

    public function testACategoryIsAddedOnceAndOthersAreKept()
    {
        $this->assertSame('cat-1', Pause::withCategory('', 'cat-1'));
        $this->assertSame('mine,cat-1', Pause::withCategory('mine', 'cat-1'));
        $this->assertSame('mine,cat-1', Pause::withCategory('mine,cat-1', 'cat-1'));
    }
}
