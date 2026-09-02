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


use OPNsense\Lens\NetflowFix;
use PHPUnit\Framework\TestCase;

/**
 * The one change Lens may make to the firewall's own configuration.
 *
 * The tests that matter are the ones about restraint: it must offer nothing
 * when there is nothing to offer, it must never propose touching an interface
 * that is not a capture candidate, and it must say what the change costs before
 * anyone presses anything.
 */
class NetflowFixTest extends TestCase
{
    private const KNOWN = [
        'wan' => 'WAN',
        'lan' => 'HOME',
        'opt1' => 'GPON',
    ];

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'interfaces' => self::KNOWN,
            'capture_interfaces' => ['wan', 'lan'],
            'collect_enabled' => true,
        ], $overrides);
    }

    public function testAFullyCapturedBoxIsOfferedNothing()
    {
        $this->assertNull(NetflowFix::plan($this->facts([
            'capture_interfaces' => ['wan', 'lan', 'opt1'],
        ])));
    }

    public function testTheMissingInterfaceIsNamedInWordsAPersonUses()
    {
        $plan = NetflowFix::plan($this->facts());

        $this->assertSame(['opt1'], $plan['adds']);
        $this->assertStringContainsString('GPON', $plan['title']);
        $this->assertSame('WAN, HOME', $plan['before']);
        $this->assertSame('WAN, HOME, GPON', $plan['after']);
    }

    public function testCaptureWithoutCollectionIsStillSomethingToFix()
    {
        /* flows measured and immediately thrown away: every figure Lens shows
           would be empty and nothing else on the page would say why */
        $plan = NetflowFix::plan($this->facts([
            'capture_interfaces' => ['wan', 'lan', 'opt1'],
            'collect_enabled' => false,
        ]));

        $this->assertNotNull($plan);
        $this->assertSame([], $plan['adds']);
        $this->assertTrue($plan['enables_collection']);
    }

    public function testTheCostIsStatedBeforeTheChangeNotAfter()
    {
        $costs = NetflowFix::plan($this->facts())['costs'];

        $this->assertNotEmpty($costs);
        $this->assertStringContainsString('GPON', implode(' ', $costs));
        $this->assertStringContainsString('/var/netflow', implode(' ', $costs));
        $this->assertStringContainsString('restarts', implode(' ', $costs));
    }

    public function testTheSettingIsNamedSoItCanBeFoundAndUndone()
    {
        $plan = NetflowFix::plan($this->facts());

        $this->assertStringContainsString('NetFlow', $plan['setting']);
        $this->assertStringContainsString('Listening interfaces', $plan['setting']);
    }

    public function testABoxCapturingNothingAtAllIsOfferedEverything()
    {
        $plan = NetflowFix::plan($this->facts([
            'capture_interfaces' => [],
            'collect_enabled' => false,
        ]));

        $this->assertSame(['wan', 'lan', 'opt1'], $plan['adds']);
        $this->assertSame('nothing', $plan['before']);
    }

    public function testLoopbackAndGroupsCannotBeProposedBecauseTheyAreNotCandidates()
    {
        /* SourceFacts::interfaceNames has already dropped them; the plan can
           only ever offer what that list holds */
        $plan = NetflowFix::plan($this->facts(['interfaces' => []]));

        $this->assertNull($plan);
    }

    public function testAnEmptyFactsArrayDoesNotProposeAnything()
    {
        $this->assertNull(NetflowFix::plan([]));
    }

    public function testCollectionIsNotOfferedWhenThereWouldBeNothingToCollect()
    {
        /*
         * Found by the test above: with no interfaces readable, the plan still
         * offered "start keeping what is captured". Pressing it would change a
         * setting, report success, and leave the page exactly as wrong as it
         * was -- the worst possible outcome for a fix button.
         */
        $this->assertNull(NetflowFix::plan([
            'interfaces' => [],
            'capture_interfaces' => [],
            'collect_enabled' => false,
        ]));
    }
}
