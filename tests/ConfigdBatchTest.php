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
use OPNsense\Lens\ConfigdBatch;
use PHPUnit\Framework\TestCase;

/**
 * Several configd commands at once (stage 58), read as core's configdRun reads one.
 */
class ConfigdBatchTest extends TestCase
{
    /** a configd stand-in: each event's answer arrives on a socket, after a delay */
    private function backend(array $answers): object
    {
        return new class ($answers) {
            private $answers;
            public $opened = [];

            public function __construct(array $answers)
            {
                $this->answers = $answers;
            }

            public function configdStream($event)
            {
                $this->opened[] = $event;
                if (!array_key_exists($event, $this->answers)) {
                    return null;
                }
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                fwrite($pair[0], $this->answers[$event]);
                fclose($pair[0]);
                return $pair[1];
            }
        };
    }

    public function testEveryAnswerArrivesCleanedAsConfigdRunCleansIt()
    {
        $backend = $this->backend([
            'service list' => "[{\"name\":\"unbound\"}]\0\0\0",
            'firmware product' => "Execute error\0\0\0",
            'lens audit' => '{"half":',               // disconnected before the end
        ]);
        $out = ConfigdBatch::run($backend, ['service list', 'firmware product', 'lens audit', 'nothing', 'service list']);

        $this->assertSame(['service list', 'firmware product', 'lens audit', 'nothing'], $backend->opened,
            'every command opened once, all before any is read');
        $this->assertSame('[{"name":"unbound"}]', $out['service list']);
        $this->assertSame('', $out['firmware product']);
        $this->assertSame('', $out['lens audit']);
        $this->assertNull($out['nothing']);
    }

    public function testALongAnswerIsReadWhole()
    {
        /* larger than a socket buffer; a temp stream, which stream_select cannot watch, is read all the same */
        $backend = new class {
            public function configdStream($event)
            {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, str_repeat('x', 300000) . "\0\0\0");
                rewind($stream);
                return $stream;
            }
        };
        $out = ConfigdBatch::run($backend, ['big']);
        $this->assertSame(300000, strlen($out['big']));
    }
}
