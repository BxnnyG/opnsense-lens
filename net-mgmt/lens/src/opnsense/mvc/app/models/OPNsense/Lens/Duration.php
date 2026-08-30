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
 * Class Duration
 *
 * How long ago, in words a human reads without arithmetic.
 *
 * The store page shipped "16477 seconds ago" to the operator's screen, which is
 * technically exact and practically unreadable. Every surface that prints an age
 * uses this, so none of them can round differently from another.
 *
 * @package OPNsense\Lens
 */
class Duration
{
    /**
     * @param int $seconds age in seconds; negative clocks read as "just now"
     * @return string
     */
    public static function ago(int $seconds): string
    {
        if ($seconds < 90) {
            return gettext('just now');
        }

        return sprintf(gettext('%s ago'), self::span($seconds));
    }

    /**
     * The same number without the "ago", for spans that are not in the past.
     *
     * @param int $seconds
     * @return string
     */
    public static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $units = [
            [86400 * 365, gettext('%s years')],
            [86400 * 7, gettext('%s weeks')],
            [86400, gettext('%s days')],
            [3600, gettext('%s hours')],
            [60, gettext('%s minutes')],
        ];

        foreach ($units as $unit) {
            list($size, $format) = $unit;
            if ($seconds >= $size * 2) {
                /* one decimal below ten, none above: "1.5 hours", "14 hours" */
                $value = $seconds / $size;
                return sprintf($format, $value < 10 ? number_format($value, 1) : (string)round($value));
            }
        }

        return sprintf(gettext('%d seconds'), $seconds);
    }
}
