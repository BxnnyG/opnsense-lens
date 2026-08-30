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


use OPNsense\Lens\SourceProbe;
use PHPUnit\Framework\TestCase;

/**
 * The configd boundary: the commands, and the three ways their replies are read.
 *
 * Every "cannot read this" case here was seen on a real box on 2026-08-30, which
 * is why they are separate cases rather than one catch-all: "the daemon is not
 * installed" and "I typed the action wrong" have to stay distinguishable.
 */
class SourceProbeTest extends TestCase
{
    public function testNoCommandUsesADottedActionName(): void
    {
        /* configd addresses [aggregate.metadata] as "aggregate metadata"; a dot
           here is the mistake that cost the first preflight four commands, and
           its reply reads like a permission problem */
        foreach (SourceProbe::commands() as $name => $command) {
            foreach (array_slice(explode(' ', $command), 1) as $word) {
                $this->assertStringNotContainsString('.', $word, $name . ': ' . $command);
            }
        }
    }

    public function testEveryCommandNamesAModuleAndAnAction(): void
    {
        foreach (SourceProbe::commands() as $name => $command) {
            $this->assertGreaterThanOrEqual(2, count(explode(' ', $command)), $name);
        }
    }

    public function testAnUnknownCommandNameThrowsRatherThanReturningEmpty(): void
    {
        /* an empty command would be handed to configd and fail obscurely */
        $this->expectException(InvalidArgumentException::class);
        SourceProbe::command('netflow_metadta');
    }

    /* ---------------------------------------------------- service state */

    public function testRunningServiceIsRecognised(): void
    {
        $this->assertTrue(SourceProbe::serviceState("flowd is running as pid 8554 9195.\n\n\n"));
        $this->assertTrue(SourceProbe::serviceState('unbound is running as pid 81864.'));
    }

    public function testStoppedServiceIsRecognisedDespiteContainingTheWordRunning(): void
    {
        /* "not running" contains "running"; order of the tests is the whole
           correctness of this function */
        $this->assertFalse(SourceProbe::serviceState('flowd_aggregate is not running.'));
        $this->assertFalse(SourceProbe::serviceState('kea is stopped'));
    }

    public function testAnUnrecognisedReplyIsUnknownNotHealthy(): void
    {
        $this->assertNull(SourceProbe::serviceState(''));
        $this->assertNull(SourceProbe::serviceState('Action not allowed or missing'));
        $this->assertNull(SourceProbe::serviceState('Traceback (most recent call last):'));
    }

    /* ---------------------------------------------------------- counting */

    public function testRecordWrapperIsCounted(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/dnsmasq-leases.json');

        $this->assertSame(2, SourceProbe::countOf($raw));
    }

    public function testBareListIsCounted(): void
    {
        /* `interface list arp json` returns a plain array, not a wrapper */
        $raw = file_get_contents(__DIR__ . '/fixtures/arp.json');

        $this->assertSame(3, SourceProbe::countOf($raw));
    }

    public function testNoneIsNotTheSameAsCouldNotRead(): void
    {
        /* a DHCP server with no leases is working; a missing one is not */
        $this->assertSame(0, SourceProbe::countOf('{"records":[]}'));
        $this->assertNull(SourceProbe::countOf(''));
        $this->assertNull(SourceProbe::countOf("\n\n  \n"));
        $this->assertNull(SourceProbe::countOf("Action not allowed or missing\n\n"));
        $this->assertNull(SourceProbe::countOf('Traceback (most recent call last):'));
    }

    /* ----------------------------------------------------------- version */

    public function testVersionIsReadFromThePackageFile(): void
    {
        $this->assertSame('0.1', SourceProbe::version(__DIR__ . '/fixtures/version-lens.json'));
    }

    public function testMissingVersionFileIsEmptyNotFatal(): void
    {
        $this->assertSame('', SourceProbe::version(__DIR__ . '/fixtures/does-not-exist'));
    }
}
