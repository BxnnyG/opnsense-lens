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

namespace OPNsense\Lens;

use OPNsense\Core\Backend;

/**
 * Class PrefetchedBackend
 *
 * Core's Backend, answering from a parallel prefetch where it has the answer
 * (stage 58). The code that reads tiles stays as it was -- it asks, and the
 * answer is simply already there; anything not prefetched goes to configd as
 * before. configdpRun() composes its event and calls configdRun(), so both are
 * served.
 *
 * @package OPNsense\Lens
 */
class PrefetchedBackend extends Backend
{
    private $answers = [];

    /** @param string[] $events commands to ask at once, before anyone asks them */
    public function prefetch(array $events): void
    {
        foreach (ConfigdBatch::run($this, $events) as $event => $answer) {
            if ($answer !== null) {
                $this->answers[$event] = $answer;
            }
        }
    }

    /** the event configdpRun() would send, so a prefetch can name it */
    public static function event(string $event, array $params): string
    {
        foreach ($params as $param) {
            $event .= ' ' . escapeshellarg($param ?? '');
        }

        return $event;
    }

    public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        if (!$detach && array_key_exists($event, $this->answers)) {
            return $this->answers[$event];
        }

        return parent::configdRun($event, $detach, $timeout, $connect_timeout);
    }
}
