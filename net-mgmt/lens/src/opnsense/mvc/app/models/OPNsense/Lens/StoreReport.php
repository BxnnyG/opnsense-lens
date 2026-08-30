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
 * What the Lens store holds, in sentences.
 *
 * Pure: the collector's status JSON in, display rows out. Same split as
 * SourceReport, for the same reason (DESIGN 4.21).
 *
 * @package OPNsense\Lens
 */
class StoreReport
{
    /** a run that has not happened within this is not running */
    public const OBSERVE_OVERDUE = 900;
    public const HARVEST_OVERDUE = 5400;

    /**
     * Hourly buckets needed before a per-hour-of-week baseline may speak.
     * Three occurrences of each bucket, so roughly three weeks (S8).
     */
    public const BASELINE_DAYS = 21;

    /**
     * @param array $status decoded output of `configctl lens status`
     * @param int $now
     * @return array headline, rows, and whether anything is wrong
     */
    public static function describe(array $status, int $now): array
    {
        if (empty($status) || !isset($status['schema_version'])) {
            return [
                'collecting' => false,
                'headline' => gettext('The store has not been created yet.'),
                'rows' => [],
            ];
        }

        $days = self::daysOfHistory($status, $now);

        return [
            'collecting' => true,
            'headline' => self::headline($status, $days),
            'rows' => array_merge(
                [
                    [
                        'what' => gettext('Devices known'),
                        'detail' => self::devices($status),
                    ],
                    [
                        'what' => gettext('Address observations'),
                        'detail' => self::observations($status, $now),
                    ],
                    [
                        'what' => gettext('Hourly traffic buckets'),
                        'detail' => self::buckets($status, $days),
                    ],
                    [
                        'what' => gettext('Database'),
                        'detail' => sprintf(
                            gettext('%s MB of a %d MB ceiling, kept for %d days'),
                            $status['size_mb'],
                            $status['ceiling_mb'],
                            $status['retention_days']
                        ),
                    ],
                ],
                self::runs($status, $now)
            ),
        ];
    }

    private static function headline(array $status, ?float $days): string
    {
        if (empty($status['traffic_rows'])) {
            return gettext(
                'Collecting. Nothing has been harvested yet -- the first hourly bucket ' .
                'appears once an hour has fully passed.'
            );
        }

        if ($days !== null && $days < self::BASELINE_DAYS) {
            return sprintf(
                gettext(
                    'Collecting. %s days of hourly history so far; a baseline needs about %d ' .
                    'before it is allowed to call anything unusual.'
                ),
                self::days($days),
                self::BASELINE_DAYS
            );
        }

        return sprintf(
            gettext('Collecting. %s days of hourly history, which OPNsense itself would have deleted.'),
            self::days($days)
        );
    }

    private static function devices(array $status): string
    {
        $detail = sprintf(gettext('%d seen'), (int)$status['devices']);

        if (!empty($status['devices_randomised'])) {
            $detail .= ' · ' . sprintf(
                gettext('%d with a randomised MAC address'),
                (int)$status['devices_randomised']
            );
        }

        return $detail;
    }

    private static function observations(array $status, int $now): string
    {
        if (empty($status['observations'])) {
            return gettext('none yet');
        }

        return sprintf(
            gettext('%d windows, the oldest opened %s days ago'),
            (int)$status['observations'],
            self::days(($now - (int)$status['first_observation']) / 86400)
        );
    }

    private static function buckets(array $status, ?float $days): string
    {
        if (empty($status['traffic_rows'])) {
            return gettext('none yet');
        }

        return sprintf(
            gettext('%d rows covering %s days'),
            (int)$status['traffic_rows'],
            self::days($days)
        );
    }

    /**
     * A duty that has not run recently is the failure that matters most here:
     * it is silent, and what it loses cannot be collected later.
     */
    private static function runs(array $status, int $now): array
    {
        $rows = [];
        $duties = [
            'observe' => [gettext('Last observation'), self::OBSERVE_OVERDUE],
            'harvest' => [gettext('Last harvest'), self::HARVEST_OVERDUE],
        ];

        foreach ($duties as $duty => $spec) {
            list($label, $overdue) = $spec;
            $run = $status['runs'][$duty] ?? null;

            if ($run === null) {
                $rows[] = ['what' => $label, 'detail' => gettext('has never run'), 'wrong' => true];
                continue;
            }

            $age = $now - (int)$run['at'];
            $detail = sprintf(
                gettext('%s, took %d ms - %s'),
                Duration::ago($age),
                (int)$run['took_ms'],
                (string)$run['detail']
            );

            $rows[] = [
                'what' => $label,
                'detail' => $detail,
                'wrong' => $age > $overdue || empty($run['ok']),
            ];
        }

        return $rows;
    }

    private static function daysOfHistory(array $status, int $now): ?float
    {
        if (empty($status['first_bucket'])) {
            return null;
        }

        return max(0, $now - (int)$status['first_bucket']) / 86400;
    }

    private static function days(?float $days): string
    {
        return $days === null ? '0' : number_format($days, 1);
    }
}
