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
 * Class IdentityHealth
 *
 * Whether keying identity on the MAC address is still a defensible choice.
 *
 * §4.17 made that choice on ten hours of a quiet Saturday and wrote a date in
 * the roadmap to re-check it. Dates in roadmaps are read late or not at all —
 * this one was read four days late — so the re-check became this: a measurement
 * the plugin takes continuously and states in words, on the page that owns the
 * question of whether Lens's own data is sound (§4.36).
 *
 * The verdict is deliberately not a score. It says what was measured and what
 * would change the answer, because the reader has to be able to disagree.
 *
 * @package OPNsense\Lens
 */
class IdentityHealth
{
    /** below this there is not enough history for the answer to mean anything */
    public const NEEDS_DAYS = 7;

    /** a device seen for less than an hour in total, ever */
    public const FLEETING = 3600;

    /**
     * @param array $raw what `lens identity` returned
     * @param int $now
     * @return array
     */
    public static function assess(array $raw, int $now): array
    {
        $devices = (int)($raw['devices'] ?? 0);
        $since = $raw['watching_since'] ?? null;
        $days = $since ? ($now - (int)$since) / 86400 : 0;

        $facts = [
            'devices' => $devices,
            'randomised' => (int)($raw['randomised'] ?? 0),
            'fleeting' => (int)($raw['fleeting'] ?? 0),
            'overlaps' => (int)($raw['overlaps'] ?? 0),
            'reused' => (int)($raw['reused'] ?? 0),
            'appeared' => (int)($raw['appeared_this_week'] ?? 0),
            'appeared_randomised' => (int)($raw['appeared_this_week_randomised'] ?? 0),
            'watching' => Duration::span((int)round($days * 86400)),
        ];

        return $facts + [
            'verdict' => self::verdict($facts, $days),
            'says' => self::says($facts, $days),
        ];
    }

    /**
     * `holding`, `watch`, `fragmenting`, or `too_early`.
     */
    private static function verdict(array $f, float $days): string
    {
        if ($days < self::NEEDS_DAYS || $f['devices'] === 0) {
            return 'too_early';
        }

        /*
         * The failure §4.17 was chosen to avoid, in the operator's own words:
         * "a device list that grows by ten entries a week and quietly lies".
         * A quarter of the list arriving in one week, most of it randomised and
         * most of it gone within the hour, is that happening.
         */
        $churning = $f['appeared'] >= max(3, (int)ceil($f['devices'] / 4))
            && $f['appeared_randomised'] * 2 >= $f['appeared'];

        if ($churning) {
            return 'fragmenting';
        }

        return $f['overlaps'] > 0 || $f['appeared'] > 0 ? 'watch' : 'holding';
    }

    /**
     * What was measured, in the order that decides the verdict. Sentences, not
     * a score: a reader who disagrees has to be able to see why.
     *
     * @return array
     */
    private static function says(array $f, float $days): array
    {
        if ($days < self::NEEDS_DAYS) {
            return [sprintf(
                gettext(
                    'Lens has been watching for %s. Identity needs about %d days '
                    . 'before the question can be answered at all - a quiet weekend '
                    . 'hides what a week of devices rejoining shows.'
                ),
                $f['watching'],
                self::NEEDS_DAYS
            )];
        }

        $says = [sprintf(
            gettext('%d devices over %s, %d of them with a randomised MAC address.'),
            $f['devices'],
            $f['watching'],
            $f['randomised']
        )];

        $says[] = $f['overlaps'] === 0
            ? gettext(
                'No address was ever held by two devices at the same time. That is '
                . 'the measurement keying on the MAC was chosen to protect, and it '
                . 'is holding.'
            )
            : sprintf(
                gettext(
                    '%d addresses were held by two devices at once. Those hours are '
                    . 'attributed to neither, and appear in the accounting under the '
                    . 'device list.'
                ),
                $f['overlaps']
            );

        if ($f['appeared'] > 0) {
            $says[] = sprintf(
                gettext(
                    '%d devices appeared this week, %d of them randomised, and %d '
                    . 'devices have ever been seen for less than an hour in total. '
                    . 'A phone rotating its address looks exactly like this, so the '
                    . 'number to watch is whether it keeps climbing.'
                ),
                $f['appeared'],
                $f['appeared_randomised'],
                $f['fleeting']
            );
        }

        return $says;
    }
}
