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
 * Class BaselineReport
 *
 * The first judgement Lens makes, and the sentences it is allowed to say.
 *
 * Everything else on these pages is a record: it happened, here is the number.
 * This says *and that is unusual*, which is a claim about what normal looks
 * like — so it states the comparison it made, in full, every time. A reader who
 * disagrees that 4 GB against a 300 MB median is worth mentioning can see the
 * 300 MB and decide for themselves (§4.50).
 *
 * @package OPNsense\Lens
 */
class BaselineReport
{
    /**
     * @param array $raw what `lens baseline` returned
     * @param array $names mac to the name DeviceReport gives it. The collector
     *                     cannot know a device's vendor name -- that is looked
     *                     up at display time (§4.23) -- so without this a
     *                     Proxmox guest was "Proxmox Server Solutions GmbH
     *                     1B5816" in the list and a bare MAC in the verdict.
     * @return array
     */
    public static function describe(array $raw, array $names = []): array
    {
        $days = (int)($raw['days'] ?? 0);
        $needs = (int)($raw['needs_days'] ?? 21);

        $rows = [];
        foreach ($raw['unusual'] ?? [] as $entry) {
            $today = (int)($entry['today'] ?? 0);
            $usual = (int)($entry['usual'] ?? 0);

            $rows[] = [
                'mac' => (string)($entry['mac'] ?? ''),
                'name' => (string)($names[$entry['mac'] ?? ''] ?? ($entry['name'] ?? ($entry['mac'] ?? ''))),
                'today' => Bytes::human($today),
                'usual' => Bytes::human($usual),
                'times' => (float)($entry['times'] ?? 0),
                'says' => sprintf(
                    gettext('%s today against a usual day of %s - %s times as much.'),
                    Bytes::human($today),
                    Bytes::human($usual),
                    rtrim(rtrim(number_format((float)($entry['times'] ?? 0), 1), '0'), '.')
                ),
            ];
        }

        return [
            'unusual' => $rows,
            'learning' => $days < $needs,
            'days' => $days,
            'needs_days' => $needs,
            'headline' => self::headline($rows, $days, $needs),
        ];
    }

    private static function headline(array $rows, int $days, int $needs): string
    {
        if ($days < $needs) {
            return sprintf(
                gettext(
                    'Still learning what is normal here: %d of the %d days it needs. '
                    . 'Until then nothing is called unusual, because in the first week '
                    . 'everything would be.'
                ),
                $days,
                $needs
            );
        }

        if ($rows === []) {
            return sprintf(
                gettext('Nothing unusual today, measured against %d days of each device.'),
                $days
            );
        }

        return sprintf(
            gettext('%d devices are moving far more than they usually do today.'),
            count($rows)
        );
    }
}
