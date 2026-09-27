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
 * What the operator may change, in words (§4.58).
 *
 * Pure. The keys, defaults and bounds belong to the collector's
 * lenslib/settings.py, which refuses anything outside them with a code; this
 * class turns its answers into a form and its codes into sentences, and decides
 * nothing about what is allowed. A bound written here as well would agree today
 * and disagree after the first edit to either.
 *
 * @package OPNsense\Lens
 */
class Settings
{
    /** what the page may send; nothing else is forwarded to the collector */
    public const KEYS = [
        'retention_days',
        'disk_ceiling_mb',
        'observation_gap',
        'probe_enabled',
        'probe_targets',
        'gateway_samples',
        'baseline_days',
        'baseline_factor',
        'baseline_floor_mb',
    ];

    /** stored in seconds, which is what the collector compares; shown in minutes */
    private const SCALE = ['observation_gap' => 60];

    /**
     * @param array $raw `lens settings` decoded: values, defaults, bounds
     * @param array $status `lens status` decoded, for what purge would remove
     * @return array blocks of fields, ready to lay out
     */
    public static function form(array $raw, array $status = []): array
    {
        if (empty($raw['values']) || !is_array($raw['values'])) {
            return ['available' => false, 'blocks' => [], 'purge' => self::purgeSummary($status)];
        }

        return [
            'available' => true,
            'blocks' => [
                [
                    'id' => 'keep',
                    'title' => gettext('Keeping data'),
                    'intro' => gettext(
                        'Everything Lens stores describes what a person did on the network. '
                        . 'These decide how long that lasts and how much room it may take.'
                    ),
                    'fields' => [
                        self::field(
                            $raw,
                            'retention_days',
                            'int',
                            gettext('Keep history for'),
                            gettext('days'),
                            gettext(
                                'Observations, traffic, gateway samples and probe results older than this are '
                                . 'deleted by the nightly prune at 04:17. Shortening it deletes the difference '
                                . 'that night, and that cannot be undone.'
                            )
                        ),
                        self::field(
                            $raw,
                            'disk_ceiling_mb',
                            'int',
                            gettext('Largest the store may grow'),
                            'MB',
                            gettext(
                                'At this size the collector stops writing rather than fill the firewall\'s disk. '
                                . 'Data Sources says when the current rate of growth reaches it.'
                            )
                        ),
                        self::field(
                            $raw,
                            'observation_gap',
                            'int',
                            gettext('A device counts as gone after'),
                            gettext('minutes'),
                            gettext(
                                'How long a device may go unseen before its visit ends. Longer joins short '
                                . 'absences into one visit; shorter makes Who\'s home more exact. Applies from the '
                                . 'next observation: visits already recorded keep the gap they were recorded under.'
                            )
                        ),
                    ],
                ],
                [
                    'id' => 'internet',
                    'title' => gettext('The internet panel'),
                    'intro' => gettext(
                        'Every five minutes Lens pings each target three times, all at once, and gives up '
                        . 'after four seconds. The internet counts as down only when every target stays '
                        . 'silent. This is the only traffic Lens sends itself.'
                    ),
                    'fields' => [
                        self::field(
                            $raw,
                            'probe_enabled',
                            'flag',
                            gettext('Probe the internet'),
                            '',
                            gettext(
                                'Off: no packets are sent, the dashboard judges the line from the gateway alone, '
                                . 'and the history already measured stays.'
                            )
                        ),
                        self::field(
                            $raw,
                            'probe_targets',
                            'targets',
                            gettext('Targets'),
                            '',
                            gettext(
                                'Up to three public IPv4 addresses. With one target, its own bad minute looks '
                                . 'like an outage; three on different operators\' networks do not.'
                            )
                        ),
                    ],
                ],
                [
                    'id' => 'line',
                    'title' => gettext('The line'),
                    'intro' => '',
                    'fields' => [
                        self::field(
                            $raw,
                            'gateway_samples',
                            'flag',
                            gettext('Keep gateway history'),
                            '',
                            gettext(
                                'Stores each gateway\'s latency, jitter and loss every five minutes for the chart '
                                . 'on the dashboard. The live reading comes from System: Gateways either way.'
                            )
                        ),
                    ],
                ],
                [
                    'id' => 'unusual',
                    'title' => gettext('Calling something unusual'),
                    'intro' => gettext(
                        'A device\'s day is called unusual only when all three hold. Changing them changes '
                        . 'today\'s verdicts; nothing already stored is rewritten.'
                    ),
                    'fields' => [
                        self::field(
                            $raw,
                            'baseline_days',
                            'int',
                            gettext('Learn for'),
                            gettext('days'),
                            gettext(
                                'Complete days of history a device needs before it is judged at all. Fewer days '
                                . 'make a quicker baseline and a noisier one.'
                            )
                        ),
                        self::field(
                            $raw,
                            'baseline_factor',
                            'float',
                            gettext('Today is at least'),
                            gettext('times its usual day'),
                            gettext(
                                'The usual day is the median, so one backup night does not move it.'
                            )
                        ),
                        self::field(
                            $raw,
                            'baseline_floor_mb',
                            'int',
                            gettext('And at least'),
                            gettext('MB more than usual'),
                            gettext(
                                'Keeps small devices quiet: 3 KB against a usual 1 KB is three times normal '
                                . 'and means nothing.'
                            )
                        ),
                    ],
                ],
            ],
            'backup' => gettext(
                'These settings live in Lens\'s own store, not in the firewall\'s configuration: a '
                . 'configuration backup does not include them, and a restore starts from the defaults.'
            ),
            'purge' => self::purgeSummary($status),
        ];
    }

    /**
     * What the page sent, restricted to known keys and in the collector's units.
     *
     * @param array $posted the request's POST body
     * @return array
     */
    public static function toStore(array $posted): array
    {
        $fields = [];

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }

            $value = $posted[$key];
            if (isset(self::SCALE[$key]) && is_numeric($value)) {
                $value = $value * self::SCALE[$key];
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * @param array $reply `lens configure` decoded
     * @return array status ok, invalid with a sentence per field, or failed
     */
    public static function outcome(array $reply): array
    {
        $status = $reply['status'] ?? null;

        if ($status === 'saved') {
            return ['status' => 'ok', 'saved' => array_values((array)($reply['saved'] ?? []))];
        }

        if ($status === 'invalid') {
            $errors = [];
            foreach ((array)($reply['errors'] ?? []) as $key => $error) {
                $errors[$key] = self::sentence((string)$key, (array)$error, (array)($reply['bounds'] ?? []));
            }

            return ['status' => 'invalid', 'errors' => $errors];
        }

        return ['status' => 'failed', 'message' => gettext('Lens did not answer. Nothing was changed.')];
    }

    /**
     * @param string $reply what configd said to `lens purge`
     * @return array
     */
    public static function purged(string $reply): array
    {
        if (trim($reply) === 'OK') {
            return ['status' => 'ok', 'result' => gettext(
                'Everything Lens had collected is gone. Collection starts again at the next observation.'
            )];
        }

        return ['status' => 'failed', 'message' => sprintf(
            gettext('Nothing was deleted: configd answered "%s".'),
            trim($reply) === '' ? gettext('nothing') : trim($reply)
        )];
    }

    /**
     * What "delete everything" removes, and what it does not, before the button.
     *
     * @param array $status `lens status` decoded
     * @return array lines for the dialogue
     */
    public static function purgeSummary(array $status): array
    {
        $removes = [];

        if (isset($status['devices'])) {
            $removes[] = sprintf(
                gettext('%d devices, with every name, kind, tag and note you gave them'),
                (int)$status['devices']
            );
            $removes[] = sprintf(gettext('%d address windows'), (int)($status['observations'] ?? 0));
            $removes[] = sprintf(gettext('%d rows of hourly traffic'), (int)($status['traffic_rows'] ?? 0));
            $removes[] = gettext('every gateway and probe sample');
            $removes[] = sprintf(gettext('in all %s MB'), (string)($status['size_mb'] ?? 0));
        } else {
            $removes[] = gettext('everything Lens has collected');
        }

        return [
            'removes' => $removes,
            'keeps' => [
                gettext('these settings'),
                gettext('the firewall\'s own data: NetFlow, leases and logs are core\'s and are not touched'),
            ],
            'after' => [
                gettext('collection starts again at the next observation'),
                gettext('the baseline learns from day 0 again, and new devices are withheld for two days again'),
                gettext('OPNsense keeps hourly per-device traffic for 24 hours, so anything older is gone for good'),
            ],
        ];
    }

    /**
     * @param array $raw
     * @param string $key
     * @param string $kind int, float, flag or targets
     * @param string $label
     * @param string $unit
     * @param string $help
     * @return array
     */
    private static function field(
        array $raw,
        string $key,
        string $kind,
        string $label,
        string $unit,
        string $help
    ): array {
        $scale = self::SCALE[$key] ?? 1;
        $bounds = (array)($raw['bounds'][$key] ?? []);

        $field = [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'unit' => $unit,
            'help' => $help,
            'value' => self::scaled($raw['values'][$key] ?? null, $scale),
            'default' => self::scaled($raw['defaults'][$key] ?? null, $scale),
        ];

        if ($kind === 'int' || $kind === 'float') {
            $field['min'] = isset($bounds[0]) ? $bounds[0] / $scale : null;
            $field['max'] = isset($bounds[1]) ? $bounds[1] / $scale : null;
            $field['step'] = $kind === 'float' ? 0.5 : 1;
        } elseif ($kind === 'targets') {
            $field['rows'] = isset($bounds[1]) ? (int)$bounds[1] : 3;
        }

        return $field;
    }

    private static function scaled($value, int $scale)
    {
        return is_numeric($value) && !is_bool($value) ? $value / $scale : $value;
    }

    /**
     * @param string $key
     * @param array $error [code, row or null]
     * @param array $bounds key to [low, high], in the collector's units
     * @return string
     */
    private static function sentence(string $key, array $error, array $bounds): string
    {
        $code = (string)($error[0] ?? '');
        $row = isset($error[1]) ? (int)$error[1] : null;
        $scale = self::SCALE[$key] ?? 1;
        $low = isset($bounds[$key][0]) ? $bounds[$key][0] / $scale : null;
        $high = isset($bounds[$key][1]) ? $bounds[$key][1] / $scale : null;

        switch ($code) {
            case 'too_small':
                return $low === null ? gettext('That is too small.')
                    : sprintf(gettext('The smallest this can be is %s.'), self::number($low));
            case 'too_large':
                return $high === null ? gettext('That is too large.')
                    : sprintf(gettext('The largest this can be is %s.'), self::number($high));
            case 'not_a_number':
                return gettext('This has to be a number.');
            case 'not_whole':
                return gettext('This has to be a whole number.');
            case 'not_a_flag':
                return gettext('This is either on or off.');
            case 'too_few':
                return gettext('Keep at least one target. To stop probing, switch the probes off instead.');
            case 'too_many':
                return sprintf(gettext('At most %d targets.'), $high === null ? 3 : (int)$high);
            case 'bad_name':
                return self::row($row, gettext(
                    'a name is up to 24 letters, digits, spaces, dots, dashes or underscores.'
                ));
            case 'bad_address':
                return self::row($row, gettext(
                    'that is not an IPv4 address. Hostnames are not accepted, because resolving one '
                    . 'would make the probe depend on DNS.'
                ));
            case 'ipv6':
                return self::row($row, gettext(
                    'IPv6 targets are not offered yet: whether ping\'s deadline works the same way for '
                    . 'IPv6 has not been checked on a box.'
                ));
            case 'not_public':
                return self::row($row, gettext(
                    'that address is not on the internet, so it cannot say whether the internet is there.'
                ));
            case 'duplicate':
                return self::row($row, gettext('that address is already a target.'));
            case 'bad_row':
                return self::row($row, gettext('this row could not be read.'));
            case 'unknown':
                return sprintf(gettext('Lens has no setting called "%s".'), $key);
            case 'not_an_object':
                return gettext('The page sent something Lens could not read.');
            default:
                /* a code this file has not learned yet still reaches the page */
                return sprintf(gettext('Refused (%s).'), $code === '' ? '?' : $code);
        }
    }

    private static function row(?int $row, string $text): string
    {
        return $row === null ? ucfirst($text) : sprintf(gettext('Row %d: %s'), $row, $text);
    }

    private static function number($value): string
    {
        return (string)(floor((float)$value) == $value ? (int)$value : $value);
    }
}
