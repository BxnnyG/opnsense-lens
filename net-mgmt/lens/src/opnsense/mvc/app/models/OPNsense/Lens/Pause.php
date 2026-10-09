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
 * Class Pause
 *
 * Everything about pausing a device that decides something (§4.74, §4.83).
 * Pure: it never touches the configuration. PauseRule writes what this class
 * worked out, through core's own models, and the controller only carries
 * requests in and answers out (§4.21).
 *
 * The second exception to rule 6 is one floating block rule and one MAC alias.
 * The alias content is the truth about who is paused; the store only remembers
 * since when, until when and how it ended.
 *
 * @package OPNsense\Lens
 */
class Pause
{
    public const ALIAS = 'lens_paused';
    public const RULE_DESCRIPTION = 'Lens: paused devices';
    public const ALIAS_DESCRIPTION = 'Lens: paused devices - managed by Services: Lens';
    /* the firewall category that groups the two (§4.85); Lens's accent colour */
    public const CATEGORY = 'Lens';
    public const CATEGORY_COLOR = 'd94f00';

    /* a week: longer than that is not a pause, it is a block, and a block is
       a rule the operator writes in Firewall: Rules where it can be read */
    public const MAX_MINUTES = 10080;

    /**
     * Why this device may not be paused from this click, or null when it may.
     *
     * @param array $target the device's row from DeviceReport
     * @param array $rows every device row, to find the one the click came from
     * @param string $client the address the request came from
     * @param array $protected interface devices ticked under Settings
     * @param array $names interface device => the name the operator gave it
     * @return string|null
     */
    public static function refuse(
        array $target,
        array $rows,
        string $client,
        array $protected,
        array $names = []
    ): ?string {
        if (!empty($target['is_local'])) {
            return gettext('This is the firewall itself.');
        }

        $macs = self::macsOf($target);
        if ($macs === []) {
            return gettext('Lens knows this device by an address only, and a pause needs its MAC.');
        }

        /* the device the click came from: whoever holds that address, by any
           of its MACs -- a folded phone is the same phone on every one */
        $requester = self::holderOf($rows, $client);
        $sameMacs = $requester !== null && array_intersect($macs, self::macsOf($requester)) !== [];
        if (self::holds($target, $client) || $sameMacs) {
            return gettext('This is the device you are using right now.');
        }

        $guarded = array_values(array_filter(array_map('strval', $protected), 'strlen'));
        $why = gettext('which a pause never touches (Services: Lens: Settings)');
        if ($guarded === []) {
            $network = $requester !== null ? self::networkOf($requester, $client) : null;
            if ($network === null) {
                return gettext(
                    'Lens cannot tell which network you are clicking from. Tick the networks a pause '
                    . 'must never touch under Services: Lens: Settings first.'
                );
            }
            $guarded = [$network];
            $why = gettext('the network you are clicking from');
        }

        /* everywhere it has been, not only where it is: the alias follows the
           MAC, so a laptop paused on one network is cut off on the next (§4.83) */
        $been = array_values(array_intersect(self::interfacesOf($target), $guarded));
        if ($been !== []) {
            return sprintf(
                gettext('It has been on %s, %s.'),
                implode(', ', array_map(function ($interface) use ($names) {
                    return $names[$interface] ?? $interface;
                }, $been)),
                $why
            );
        }

        return null;
    }

    /**
     * @param mixed $raw minutes as posted: 0 means until resumed
     * @return int|null null when it is not a duration Lens accepts
     */
    public static function minutes($raw): ?int
    {
        $raw = trim((string)$raw);
        if (!preg_match('/^[0-9]{1,6}$/', $raw)) {
            return null;
        }
        $minutes = (int)$raw;

        return $minutes <= self::MAX_MINUTES ? $minutes : null;
    }

    /**
     * @return int|null when the pause ends, null for until resumed
     */
    public static function until(int $minutes, int $now): ?int
    {
        return $minutes === 0 ? null : $now + $minutes * 60;
    }

    /**
     * The MACs in an alias's content, lower case, in order, once each.
     */
    public static function macsIn(string $content): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($content)) as $mac) {
            if ($mac !== '' && !in_array($mac, $out, true)) {
                $out[] = $mac;
            }
        }

        return $out;
    }

    /**
     * The content with these MACs added; whoever else is paused stays.
     */
    public static function contentWith(string $content, array $macs): string
    {
        return implode("\n", self::macsIn($content . "\n" . implode("\n", $macs)));
    }

    /**
     * The content with these MACs gone; whoever else is paused stays.
     */
    public static function contentWithout(string $content, array $macs): string
    {
        $gone = self::macsIn(implode("\n", $macs));

        return implode("\n", array_values(array_diff(self::macsIn($content), $gone)));
    }

    /**
     * The alias, as core's Alias model fields.
     */
    public static function aliasFields(string $content, string $category = ''): array
    {
        return array_merge([
            'enabled' => '1',
            'name' => self::ALIAS,
            'type' => 'mac',
            'content' => $content,
            'description' => self::ALIAS_DESCRIPTION,
        ], $category !== '' ? ['categories' => $category] : []);
    }

    public const REASON_MAX = 60;

    /**
     * The operator's reason for a pause (operator, 2026-10-09): one printable
     * line, at most REASON_MAX characters, '' for none. The collector cleans it
     * again; this is what the page and the alias get.
     */
    public static function reason(string $text): string
    {
        $text = preg_replace('/[^\P{C}]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return function_exists('mb_substr')
            ? mb_substr($text, 0, self::REASON_MAX)
            : substr($text, 0, self::REASON_MAX);
    }

    /** a reason as the collector's --reason takes it: base64url, '-' for none */
    public static function reasonParameter(string $reason): string
    {
        return $reason === '' ? '-' : rtrim(strtr(base64_encode($reason), '+/', '-_'), '=');
    }

    /**
     * The alias's description: what it is, and why its devices are paused --
     * the reasons only, never a device's name or MAC (a name is the LAN's
     * word, edge case 6). Core's description field is kept under 255.
     *
     * @param array $reasons the reasons of every pause still running, any order
     */
    public static function aliasDescription(array $reasons): string
    {
        $reasons = array_values(array_unique(array_filter(array_map('strval', $reasons), 'strlen')));
        if ($reasons === []) {
            return self::ALIAS_DESCRIPTION;
        }
        $text = self::ALIAS_DESCRIPTION . ' - ' . implode(', ', $reasons);

        return strlen($text) > 250 ? substr($text, 0, 247) . '...' : $text;
    }

    /**
     * The reasons of the pauses still running after a change.
     *
     * @param array $open the store's open pauses
     * @param array $ending device keys whose pause ends now
     * @param string|null $adding the reason of the pause starting now
     */
    public static function reasonsAfter(array $open, array $ending, ?string $adding = null): array
    {
        $reasons = [];
        foreach ($open as $pause) {
            if (!in_array((string)($pause['mac'] ?? ''), $ending, true) && !empty($pause['reason'])) {
                $reasons[] = (string)$pause['reason'];
            }
        }
        if ($adding !== null && $adding !== '') {
            $reasons[] = $adding;
        }

        return $reasons;
    }

    /** the category's fields, as core's Category model holds them (§4.85) */
    public static function categoryFields(): array
    {
        return ['name' => self::CATEGORY, 'color' => self::CATEGORY_COLOR, 'auto' => '0'];
    }

    /**
     * A comma list of category uuids with one more in it, once.
     */
    public static function withCategory(string $categories, string $uuid): string
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $categories)), 'strlen'));
        if (!in_array($uuid, $list, true)) {
            $list[] = $uuid;
        }

        return implode(',', $list);
    }

    /**
     * The rule, as core's Filter model fields: floating on every interface,
     * inbound, both address families, any protocol, logged so a paused
     * device's attempts show in the live log under the rule's own name.
     */
    public static function ruleFields(int $sequence, string $category = ''): array
    {
        return array_merge([
            'enabled' => '1',
            'action' => 'block',
            'quick' => '1',
            'interface' => '',
            'direction' => 'in',
            'ipprotocol' => 'inet46',
            'protocol' => 'any',
            'source_net' => self::ALIAS,
            'source_not' => '0',
            'destination_net' => 'any',
            'destination_not' => '0',
            'log' => '1',
            'sequence' => (string)$sequence,
            'description' => self::RULE_DESCRIPTION,
        ], $category !== '' ? ['categories' => $category] : []);
    }

    /**
     * Which existing rule is Lens's: a block rule whose source is the alias.
     * By what it does rather than by its description, so a rule the operator
     * renamed is still found and a second one is never added beside it.
     *
     * @param array $rules uuid => ['source_net' =>, 'action' =>, ...]
     * @return string|null its uuid
     */
    public static function findRule(array $rules): ?string
    {
        foreach ($rules as $uuid => $rule) {
            $sources = array_map('trim', explode(',', (string)($rule['source_net'] ?? '')));
            if (($rule['action'] ?? '') === 'block' && in_array(self::ALIAS, $sources, true)) {
                return (string)$uuid;
            }
        }

        return null;
    }

    /**
     * The sequence that puts a new rule first among the floating rules, and
     * changes nobody else's: one below the lowest, never below one.
     *
     * @param array $rules uuid => ['interface' =>, 'interfacenot' =>, 'sequence' =>]
     */
    public static function firstSequence(array $rules): int
    {
        $lowest = null;
        foreach ($rules as $rule) {
            $interfaces = array_filter(explode(',', (string)($rule['interface'] ?? '')), 'strlen');
            $floating = count($interfaces) !== 1 || !empty($rule['interfacenot']);
            if ($floating && is_numeric($rule['sequence'] ?? null)) {
                $lowest = $lowest === null ? (int)$rule['sequence'] : min($lowest, (int)$rule['sequence']);
            }
        }

        return $lowest === null ? 1 : max(1, $lowest - 1);
    }

    /**
     * Who is paused, from the alias and the store together.
     *
     * The alias decides: a MAC in it is paused, whatever the store says. An
     * open pause whose MACs have all left the alias was ended outside Lens and
     * is reported as stale, for the caller to close.
     *
     * @param array $aliasMacs what the alias holds now
     * @param array $open the store's open pauses: ['mac', 'macs', 'started', 'until']
     * @param string|null $rule 'ok', 'disabled' or null when there is none
     * @return array
     */
    public static function status(array $aliasMacs, array $open, ?string $rule): array
    {
        $paused = [];
        $stale = [];
        $known = [];

        foreach ($open as $pause) {
            $macs = self::macsIn(implode("\n", (array)($pause['macs'] ?? [])));
            $inAlias = array_values(array_intersect($macs, $aliasMacs));
            if ($inAlias === []) {
                $stale[] = (string)($pause['mac'] ?? '');
                continue;
            }
            foreach ($macs as $mac) {
                $known[$mac] = true;
                $paused[$mac] = [
                    'key' => (string)($pause['mac'] ?? $mac),
                    'by' => 'lens',
                    'since' => (int)($pause['started'] ?? 0),
                    'reason' => isset($pause['reason']) ? (string)$pause['reason'] : null,
                    'until' => isset($pause['until']) ? (int)$pause['until'] : null,
                ];
            }
        }

        foreach ($aliasMacs as $mac) {
            if (!isset($known[$mac])) {
                $paused[$mac] = ['key' => $mac, 'by' => 'alias', 'since' => null, 'until' => null, 'reason' => null];
            }
        }

        return [
            'paused' => $paused,
            'stale' => array_values(array_filter($stale, 'strlen')),
            'rule' => $rule,
            /* MACs in an alias block nothing without the rule */
            'effective' => $rule === 'ok',
        ];
    }

    /**
     * One device's pause, from status(): null when none of its MACs is paused.
     */
    public static function ofDevice(array $row, array $status): ?array
    {
        foreach (self::macsOf($row) as $mac) {
            if (isset($status['paused'][$mac])) {
                return $status['paused'][$mac] + ['effective' => !empty($status['effective'])];
            }
        }

        return null;
    }

    /**
     * What resuming takes out of the alias: the MACs asked for, every MAC the
     * device folds now, and every MAC an open pause of it was given -- even one
     * the device no longer folds, so nothing it was paused with stays behind.
     *
     * @param array $macs what the browser named
     * @param array|null $target the device's row, if Lens still knows it
     * @param array $open the store's open pauses
     * @return array ['macs' => to remove, 'keys' => open pauses to close]
     */
    public static function resumeSet(array $macs, ?array $target, array $open): array
    {
        $remove = self::macsIn(implode("\n", array_merge($macs, $target === null ? [] : self::macsOf($target))));
        $keys = [];
        foreach ($open as $pause) {
            $given = self::macsIn(implode("\n", (array)($pause['macs'] ?? [])));
            if (array_intersect($given, $remove) !== []) {
                $remove = self::macsIn(implode("\n", array_merge($remove, $given)));
                $keys[] = (string)($pause['mac'] ?? '');
            }
        }

        return ['macs' => $remove, 'keys' => array_values(array_filter($keys, 'strlen'))];
    }

    /**
     * The open pauses whose time has come.
     *
     * @return array their keys
     */
    public static function due(array $open, int $now): array
    {
        $out = [];
        foreach ($open as $pause) {
            if (isset($pause['until']) && $pause['until'] !== null && (int)$pause['until'] <= $now) {
                $out[] = (string)$pause['mac'];
            }
        }

        return $out;
    }

    private static function macsOf(array $row): array
    {
        $macs = (array)($row['macs'] ?? []);
        if ($macs === [] && !empty($row['mac'])) {
            $macs = [$row['mac']];
        }

        return self::macsIn(implode("\n", array_map('strval', $macs)));
    }

    private static function interfacesOf(array $row): array
    {
        $out = array_map('strval', (array)($row['interfaces'] ?? []));
        foreach ((array)($row['addresses'] ?? []) as $address) {
            if (!empty($address['interface'])) {
                $out[] = (string)$address['interface'];
            }
        }

        return array_values(array_unique($out));
    }

    private static function holds(array $row, string $client): bool
    {
        foreach ((array)($row['addresses'] ?? []) as $address) {
            if (self::same((string)($address['address'] ?? ''), $client)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The device holding this address; one that holds it now beats one that
     * held it before.
     */
    private static function holderOf(array $rows, string $client): ?array
    {
        $before = null;
        foreach ($rows as $row) {
            foreach ((array)($row['addresses'] ?? []) as $address) {
                if (!self::same((string)($address['address'] ?? ''), $client)) {
                    continue;
                }
                if (!empty($address['current'])) {
                    return $row;
                }
                $before = $before ?? $row;
            }
        }

        return $before;
    }

    private static function networkOf(array $row, string $client): ?string
    {
        $found = null;
        foreach ((array)($row['addresses'] ?? []) as $address) {
            if (self::same((string)($address['address'] ?? ''), $client) && !empty($address['interface'])) {
                if (!empty($address['current'])) {
                    return (string)$address['interface'];
                }
                $found = $found ?? (string)$address['interface'];
            }
        }

        return $found;
    }

    /**
     * Two spellings of one address are one address (IPv6 is written many ways).
     */
    private static function same(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }
        $a = @inet_pton($left);
        $b = @inet_pton($right);

        return $a !== false && $b !== false ? $a === $b : strtolower($left) === strtolower($right);
    }
}
