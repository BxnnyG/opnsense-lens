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
 * Class ConfigdBatch
 *
 * Several configd commands at once (stage 58): core's own
 * Backend::configdStream() opens each (identical on stable/26.1 and 26.7), and
 * the answers are read as they arrive -- the health row asked twelve sources
 * one after another, 1.4 s on box-2, the slowest of them 0.3 s. Each answer is
 * cleaned exactly as Backend::configdRun() cleans it.
 *
 * @package OPNsense\Lens
 */
class ConfigdBatch
{
    private const END = "\0\0\0";
    private const ERROR = 'Execute error';

    /**
     * @param object $backend core's Backend (anything with configdStream())
     * @param string[] $events configd commands, as configdRun() takes them
     * @param float $timeout seconds for all of them together
     * @return array event => answer as configdRun() returns it; '' on error,
     *               null where no stream could be opened or time ran out
     */
    public static function run($backend, array $events, float $timeout = 30.0): array
    {
        $streams = [];
        $buffers = [];
        $out = [];
        foreach (array_unique($events) as $event) {
            $stream = $backend->configdStream($event);
            if (is_resource($stream)) {
                stream_set_blocking($stream, false);
                $streams[$event] = $stream;
                $buffers[$event] = '';
            } else {
                $out[$event] = null;
            }
        }

        $deadline = microtime(true) + $timeout;
        while ($streams !== [] && microtime(true) < $deadline) {
            $ready = array_values($streams);
            $none = null;
            $other = null;
            if (@stream_select($ready, $none, $other, 0, 200000) === false) {
                usleep(20000);
            }
            foreach ($streams as $event => $stream) {
                $chunk = fread($stream, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $buffers[$event] .= $chunk;
                }
                $ended = strpos($buffers[$event], self::END) !== false;
                if ($ended || feof($stream)) {
                    fclose($stream);
                    unset($streams[$event]);
                    /* as configdRun: disconnected before the end is no answer */
                    $out[$event] = $ended ? self::clean($buffers[$event]) : '';
                }
            }
        }
        foreach ($streams as $event => $stream) {
            fclose($stream);
            $out[$event] = null;
        }

        return $out;
    }

    private static function clean(string $answer): string
    {
        if (strncmp($answer, self::ERROR, strlen(self::ERROR)) === 0) {
            return '';
        }

        return str_replace(self::END, '', $answer);
    }
}
