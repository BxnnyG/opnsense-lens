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
 * Class Headline
 *
 * One sentence, for someone who will read only that line.
 *
 * Everything on the dashboard is already true; the question this answers is
 * which *one* thing to say first. The order is a decision (§4.53): a collector
 * that stopped outranks everything, because every other sentence would be about
 * the past without saying so; then something unusual; then something new; and
 * only then "everything looks normal" -- which is only allowed to be said once
 * nothing above it applies.
 *
 * @package OPNsense\Lens
 */
class Headline
{
    /**
     * @param array $summary DeviceReport's summary
     * @param array $baseline BaselineReport's output
     * @param int|null $observedAt the last observation, null if never
     * @param bool $stale whether that observation is too old to call current
     * @param string $window the range the page is showing, in words
     * @param int $now
     * @return array ['tone' => calm|notice|alert, 'icon' => fa class, 'sentence' => ..., 'also' => [...]]
     */
    public static function compose(
        array $summary,
        array $baseline,
        ?int $observedAt,
        bool $stale,
        string $window,
        int $now
    ): array {
        $unusual = (array)($baseline['unusual'] ?? []);
        $new = !empty($summary['new_yet']) ? (array)($summary['new'] ?? []) : [];
        $also = [];

        if ($observedAt === null) {
            return self::said('alert', 'fa-hourglass-o', gettext(
                'Lens has not looked at the network yet. The first observation runs within five minutes.'
            ), []);
        }

        if ($stale) {
            return self::said('alert', 'fa-exclamation-circle', sprintf(
                gettext('Lens last looked %s. Everything below is what was true then, not now.'),
                Duration::ago($now - $observedAt)
            ), []);
        }

        if ($unusual !== []) {
            $first = $unusual[0];
            $sentence = count($unusual) === 1
                ? sprintf(
                    gettext('One thing is unusual today: %s moved %s, %s times its usual day.'),
                    $first['name'],
                    $first['today'],
                    self::times($first['times'] ?? 0)
                )
                : sprintf(
                    gettext('%d devices are unusual today, led by %s at %s times its usual day.'),
                    count($unusual),
                    $first['name'],
                    self::times($first['times'] ?? 0)
                );
            if ($new !== []) {
                $also[] = self::joined($new);
            }
            return self::said('notice', 'fa-bell', $sentence, $also);
        }

        if ($new !== []) {
            return self::said('notice', 'fa-user-plus', self::joined($new), []);
        }

        $sentence = sprintf(
            gettext('Everything looks normal: %d devices home, %s moved over the last %s.'),
            (int)($summary['here'] ?? 0),
            (string)($summary['moved'] ?? '0 B'),
            $window
        );

        if (!empty($baseline['learning'])) {
            $also[] = sprintf(
                gettext('Lens is still learning what normal means here: day %d of %d.'),
                (int)($baseline['days'] ?? 0),
                (int)($baseline['needs_days'] ?? 21)
            );
        }

        return self::said('calm', 'fa-check-circle', $sentence, $also);
    }

    private static function joined(array $new): string
    {
        return count($new) === 1
            ? sprintf(gettext('%s joined the network for the first time today.'), $new[0])
            : sprintf(
                gettext('%d devices joined the network for the first time today, among them %s.'),
                count($new),
                $new[0]
            );
    }

    private static function times($times): string
    {
        return rtrim(rtrim(number_format((float)$times, 1), '0'), '.');
    }

    private static function said(string $tone, string $icon, string $sentence, array $also): array
    {
        return ['tone' => $tone, 'icon' => $icon, 'sentence' => $sentence, 'also' => $also];
    }
}
