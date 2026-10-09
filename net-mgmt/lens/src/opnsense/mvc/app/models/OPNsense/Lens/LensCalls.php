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

/**
 * Class LensCalls
 *
 * Several of the collector's reads in one configd call (stage 54): every
 * `configctl lens ...` starts Python afresh -- 0.13 s on router-01 before any
 * work -- and one page asked for five. `lens bundle` answers them from one
 * process and one open store, in order.
 *
 * @package OPNsense\Lens
 */
class LensCalls
{
    /**
     * @param object $backend core's Backend, or anything with configdpRun()
     * @param array $calls key => [duty, options] -- options: hours, mac, days, at, step
     * @param array $timing filled in: 'lens bundle (keys)' => milliseconds
     * @return array key => the read's decoded answer, [] where it had none
     */
    public static function many($backend, array $calls, array &$timing = []): array
    {
        $keys = array_keys($calls);
        $list = array_map(function ($call) {
            return [(string)$call[0], (object)($call[1] ?? [])];
        }, array_values($calls));

        $started = microtime(true);
        $raw = (string)$backend->configdpRun('lens bundle', [self::encode($list)]);
        $timing['lens bundle (' . implode(', ', $keys) . ')'] = (int)round((microtime(true) - $started) * 1000);

        $answer = json_decode(trim($raw), true);
        $answers = is_array($answer) && !isset($answer['error']) && count($answer) === count($keys)
            ? array_values($answer) : [];

        $out = [];
        foreach ($keys as $index => $key) {
            $out[$key] = is_array($answers[$index] ?? null) ? $answers[$index] : [];
        }

        return $out;
    }

    /** base64url of JSON: configd splits a parameter on spaces */
    public static function encode($value): string
    {
        return rtrim(strtr(base64_encode((string)json_encode($value)), '+/', '-_'), '=');
    }
}
