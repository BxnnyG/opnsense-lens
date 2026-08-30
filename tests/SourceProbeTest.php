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
 * What a configd answer means.
 *
 * Every "silent" case here was seen on a real box on 2026-08-30, which is why
 * they are separate cases rather than one "not JSON" catch-all: the operator
 * has to be able to tell "I typed the action wrong" apart from "this daemon is
 * not installed".
 */
class SourceProbeTest extends TestCase
{
    public function testEmptyOutputIsSilent(): void
    {
        $result = SourceProbe::interpret('');

        $this->assertFalse($result['answered']);
        $this->assertSame('no answer', $result['detail']);
    }

    public function testWhitespaceOnlyOutputIsSilent(): void
    {
        /* configd pads its replies with trailing newlines */
        $this->assertFalse(SourceProbe::interpret("\n\n  \n")['answered']);
    }

    public function testUnknownActionIsNamedAsSuch(): void
    {
        /* the reply to `configctl netflow collect.status` -- a syntax mistake
           that reads like a permission problem (DESIGN 1.8) */
        $result = SourceProbe::interpret("Action not allowed or missing\n\n\n");

        $this->assertFalse($result['answered']);
        $this->assertSame('configd does not know this action', $result['detail']);
    }

    public function testPlainTextIsNotAnAnswer(): void
    {
        /* a backing script that failed prints its complaint, not JSON */
        $result = SourceProbe::interpret("Traceback (most recent call last):\n  File ...");

        $this->assertFalse($result['answered']);
        $this->assertSame('answered, but not with data', $result['detail']);
    }

    public function testRecordListIsCounted(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/dnsmasq-leases.json');
        $result = SourceProbe::interpret($raw);

        $this->assertTrue($result['answered']);
        $this->assertSame('2 records', $result['detail']);
    }

    public function testEmptyRecordListStillAnswers(): void
    {
        /* a DHCP server with no leases is working, not broken */
        $result = SourceProbe::interpret('{"records":[]}');

        $this->assertTrue($result['answered']);
        $this->assertSame('0 records', $result['detail']);
    }

    public function testAggregationReportsItsAge(): void
    {
        $raw = json_encode(['last_sync' => time() - 42, 'aggregators' => []]);
        $result = SourceProbe::interpret($raw);

        $this->assertTrue($result['answered']);
        $this->assertMatchesRegularExpression('/last aggregated 4[123] seconds ago/', $result['detail']);
    }

    public function testAggregationInTheFutureIsNotNegative(): void
    {
        /* clock skew after a time sync must not print "-9 seconds ago" */
        $raw = json_encode(['last_sync' => time() + 600]);

        $this->assertSame('last aggregated 0 seconds ago', SourceProbe::interpret($raw)['detail']);
    }

    public function testBareListIsCounted(): void
    {
        /* `interface list arp json` returns a plain array, not a record wrapper */
        $raw = file_get_contents(__DIR__ . '/fixtures/arp.json');

        $this->assertSame('3 entries', SourceProbe::interpret($raw)['detail']);
    }

    public function testVersionIsReadFromThePackageFile(): void
    {
        $this->assertSame('0.1', SourceProbe::version(__DIR__ . '/fixtures/version-lens.json'));
    }

    public function testMissingVersionFileIsEmptyNotFatal(): void
    {
        $this->assertSame('', SourceProbe::version(__DIR__ . '/fixtures/does-not-exist'));
    }

    public function testEveryProbeIsWellFormed(): void
    {
        /* a probe without an "enables" line would render a red dot with no
           explanation, which VISION forbids */
        foreach (SourceProbe::probes() as $probe) {
            foreach (['id', 'label', 'command', 'enables'] as $key) {
                $this->assertArrayHasKey($key, $probe);
                $this->assertNotSame('', $probe[$key]);
            }
        }
    }

    public function testNoProbeUsesADottedActionName(): void
    {
        /* configd addresses [aggregate.metadata] as "aggregate metadata"; a dot
           here is the mistake that cost the first preflight four commands */
        foreach (SourceProbe::probes() as $probe) {
            $verb = explode(' ', $probe['command'])[1] ?? '';
            $this->assertStringNotContainsString('.', $verb, $probe['id'] . ': ' . $probe['command']);
        }
    }
}
