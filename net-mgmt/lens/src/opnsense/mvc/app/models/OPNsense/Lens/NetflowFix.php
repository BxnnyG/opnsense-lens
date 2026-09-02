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
 * Class NetflowFix
 *
 * The one change Lens is allowed to make to the firewall's own configuration:
 * switching on the sources it reads (§4.5). Pure — it decides *what* would
 * change and what that costs; the controller performs it.
 *
 * Two properties matter more than the convenience:
 *
 * 1. **The plan is recomputed at apply time and never sent by the browser.**
 *    A page that posts "set the capture list to X" is not a fix button, it is
 *    an unlabelled configuration API. The client asks Lens to do the thing it
 *    already offered; Lens works out again what that thing is.
 * 2. **The preview names the setting, in the words of the page that owns it.**
 *    An operator has to be able to find it afterwards, and undo it there.
 *
 * @package OPNsense\Lens
 */
class NetflowFix
{
    /**
     * @param array $facts the netflow half of SourceFacts::assemble()
     * @return array|null null when there is nothing to offer
     */
    public static function plan(array $facts): ?array
    {
        $known = (array)($facts['interfaces'] ?? []);
        $captured = (array)($facts['capture_interfaces'] ?? []);
        $collecting = !empty($facts['collect_enabled']);

        $adds = array_values(array_diff(array_keys($known), $captured));
        $after = array_values(array_unique(array_merge($captured, $adds)));

        if ($adds === [] && $collecting) {
            return null;
        }

        /* Nothing to keep. Switching collection on while no interface is
           captured changes a setting and produces no data -- a button that
           reports success and leaves the page exactly as wrong as it was. */
        if ($after === []) {
            return null;
        }

        $names = array_values(array_intersect_key($known, array_flip($adds)));

        return [
            'adds' => $adds,
            'adds_names' => $names,
            'enables_collection' => !$collecting,
            'title' => self::title($names, !$collecting),
            'setting' => gettext('Reporting: NetFlow, under "Listening interfaces"'),
            'before' => self::listOf(array_values(array_intersect_key($known, array_flip($captured)))),
            'after' => self::listOf(array_values(array_intersect_key($known, array_flip($after)))),
            'costs' => self::costs($names, !$collecting),
        ];
    }

    private static function title(array $names, bool $enables): string
    {
        if ($names === []) {
            return gettext('Start keeping the flows that are already being captured');
        }

        return sprintf(
            $enables
                ? gettext('Capture %s as well, and start keeping what is captured')
                : gettext('Capture %s as well'),
            implode(', ', $names)
        );
    }

    /**
     * Stated before the change, not after. Every one of these is a real
     * consequence on the operator's box, not a disclaimer.
     *
     * @return array
     */
    private static function costs(array $names, bool $enables): array
    {
        $costs = [];

        if ($names !== []) {
            $costs[] = sprintf(
                gettext(
                    'Every flow on %s starts being recorded. On a busy segment that '
                    . 'is the largest single thing NetFlow writes.'
                ),
                implode(', ', $names)
            );
        }

        if ($enables) {
            $costs[] = gettext(
                'Flow data starts being kept and aggregated. Nothing was being '
                . 'stored before this, so every figure Lens shows begins here.'
            );
        }

        $costs[] = gettext(
            'Disk under /var/netflow grows. OPNsense caps it by age, not by size: '
            . 'per interface a year, per device one day at full detail.'
        );
        $costs[] = gettext(
            'The NetFlow service restarts. Flows in the current five-minute window '
            . 'may be lost; nothing already written is touched.'
        );

        return $costs;
    }

    private static function listOf(array $names): string
    {
        return $names === [] ? gettext('nothing') : implode(', ', $names);
    }
}
