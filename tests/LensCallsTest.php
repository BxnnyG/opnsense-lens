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
use OPNsense\Lens\LensCalls;
use PHPUnit\Framework\TestCase;

/**
 * Several reads in one configd call (stage 54).
 */
class LensCallsTest extends TestCase
{
    private function backend(string $answer): object
    {
        return new class ($answer) {
            public $asked = [];
            private $answer;

            public function __construct(string $answer)
            {
                $this->answer = $answer;
            }

            public function configdpRun($event, $params = [])
            {
                $this->asked[] = [$event, $params];
                return $this->answer;
            }
        };
    }

    public function testOneCallCarriesEveryReadAndTheAnswersComeBackByName()
    {
        $backend = $this->backend('[{"runs": {}}, [1, 2], null]');
        $timing = [];
        $read = LensCalls::many($backend, [
            'status' => ['brief'],
            'devices' => ['devices'],
            'traffic' => ['traffic', ['hours' => 24]],
        ], $timing);

        $this->assertCount(1, $backend->asked);
        $this->assertSame('lens bundle', $backend->asked[0][0]);
        $sent = json_decode(base64_decode(strtr($backend->asked[0][1][0], '-_', '+/')), true);
        $this->assertSame([['brief', []], ['devices', []], ['traffic', ['hours' => 24]]], $sent);
        $this->assertSame(['runs' => []], $read['status']);
        $this->assertSame([1, 2], $read['devices']);
        $this->assertSame([], $read['traffic'], 'a read without an answer is empty, not null');
        $this->assertArrayHasKey('lens bundle (status, devices, traffic)', $timing);
    }

    public function testAnAnswerThatDoesNotFitLeavesEveryReadEmpty()
    {
        foreach (['', '{"error": "no"}', '[1]', 'nonsense'] as $answer) {
            $read = LensCalls::many($this->backend($answer), ['a' => ['brief'], 'b' => ['devices']]);
            $this->assertSame(['a' => [], 'b' => []], $read, $answer);
        }
    }
}
