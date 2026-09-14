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
 * Class Window
 *
 * What a page was asked for, against what the store can actually answer with.
 *
 * Every page was hard-wired to 24 hours while the store keeps a year, so the
 * question never came up. The moment a range can be chosen it does: **asking
 * for thirty days on a box that has collected eleven is not an error, but
 * showing eleven days under a heading that says thirty is** (§4.43).
 *
 * @package OPNsense\Lens
 */
class Window
{
    /** the ranges offered, in hours */
    public const CHOICES = [24, 24 * 7, 24 * 30];

    public const DEFAULT_HOURS = 24;

    /** beyond this an hourly chart is more bars than pixels */
    public const DAILY_ABOVE = 72;

    /**
     * @param mixed $asked whatever arrived in the query string
     * @return int a sane number of hours
     */
    public static function hours($asked): int
    {
        $hours = (int)$asked;

        return in_array($hours, self::CHOICES, true) ? $hours : self::DEFAULT_HOURS;
    }

    /**
     * @param int $hours what the page asked for
     * @param int|null $firstBucket the oldest thing in the store, if any
     * @param int $now
     * @return array
     */
    public static function describe(int $hours, ?int $firstBucket, int $now): array
    {
        $have = $firstBucket === null ? 0 : max(0, $now - $firstBucket);
        $asked = $hours * 3600;

        return [
            'hours' => $hours,
            'asked' => Duration::span($asked),
            'covered' => Duration::span(min($have, $asked)),
            'short' => $firstBucket !== null && $have < $asked - 3600,
            'empty' => $firstBucket === null,
            'note' => self::note($have, $asked, $firstBucket),
            'resolution' => $hours > self::DAILY_ABOVE ? 'day' : 'hour',
            'choices' => self::CHOICES,
        ];
    }

    private static function note(int $have, int $asked, ?int $firstBucket): ?string
    {
        if ($firstBucket === null) {
            return gettext('Lens has no traffic history yet, so this range is empty.');
        }

        if ($have >= $asked - 3600) {
            return null;
        }

        return sprintf(
            gettext(
                'Lens has been collecting for %s, so this is that much rather than the '
                . '%s asked for. Nothing is missing - it had not started yet.'
            ),
            Duration::span($have),
            Duration::span($asked)
        );
    }
}
