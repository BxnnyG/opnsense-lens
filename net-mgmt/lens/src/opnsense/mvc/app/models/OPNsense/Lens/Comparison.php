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
 * Class Comparison
 *
 * This range beside the same range a week earlier (§4.66). Withheld unless
 * Lens watched all of the earlier one, and said in words, not colours: more
 * traffic is not worse. Pure.
 *
 * @package OPNsense\Lens
 */
class Comparison
{
    /**
     * @param int $now octets in this range
     * @param int $before octets in the earlier one
     * @return array ['text' => '+35%', 'direction' => up|down|same|new|none]
     */
    public static function delta(int $now, int $before): array
    {
        if ($before === 0) {
            return $now === 0
                ? ['text' => '', 'direction' => 'none']
                : ['text' => gettext('new'), 'direction' => 'new'];
        }

        $change = ($now - $before) / $before * 100;
        if (abs($change) < 1) {
            return ['text' => gettext('same'), 'direction' => 'same'];
        }

        /* a minus sign, not a hyphen: it reads as a number */
        $figure = abs($change) >= 10 ? (string)round(abs($change)) : number_format(abs($change), 1);

        return [
            'text' => ($change > 0 ? '+' : "\u{2212}") . $figure . '%',
            'direction' => $change > 0 ? 'up' : 'down',
        ];
    }

    /**
     * @param array $previous `lens traffic`'s 'previous'
     * @param int $now attributed octets in this range
     * @param int $before attributed octets in the earlier one
     * @param int $hours the range
     * @return array
     */
    public static function describe(array $previous, int $now, int $before, int $hours): array
    {
        $label = $hours <= 24
            ? gettext('against the same day last week')
            : ($hours <= 168 ? gettext('against the week before') : gettext('against the 30 days before'));

        if (empty($previous['covered'])) {
            return [
                'covered' => false,
                'label' => $label,
                'note' => $previous === []
                    ? ''
                    : sprintf(
                        gettext('Nothing to compare %s yet: Lens was not collecting for all of it.'),
                        $label
                    ),
            ];
        }

        return [
            'covered' => true,
            'label' => $label,
            'total' => self::delta($now, $before),
            'before' => Bytes::human($before),
            'note' => '',
        ];
    }
}
