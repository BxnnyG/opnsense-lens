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
 * Class DeviceType
 *
 * What kind of thing this probably is, from the hardware vendor and the name it
 * announces. Pure, and deliberately small.
 *
 * **This is a hint and it is labelled as one.** Everything else Lens shows was
 * observed; this is inferred from a string, and it will be wrong sometimes -- an
 * Intel NIC in a NAS reads as a computer, an Apple MAC in a TV reads as a phone.
 * It earns its place because fifty rows of identical text are unreadable and an
 * icon column makes them scannable, not because the guess is valuable in itself.
 * So: no match produces a neutral mark, never a plausible-looking wrong one, and
 * stage 6 lets the operator override it permanently.
 *
 * FontAwesome 4 class names -- that is what OPNsense 26 ships (verified against
 * `fa-trash-o` and `fa-refresh` across the plugin collection, 2026-08-30).
 *
 * @package OPNsense\Lens
 */
class DeviceType
{
    /**
     * Ordered: the first pattern that matches wins, so the specific cases come
     * before the general ones. A printer is a computer to any vendor list.
     *
     * @return array of [icon, label, needle...]
     */
    private static function rules(): array
    {
        return [
            ['printer', 'fa-print', 'Printer',
             'brother', 'lexmark', 'kyocera', 'laserjet', 'officejet', 'printer'],
            ['phone', 'fa-mobile', 'Phone or tablet',
             'iphone', 'ipad', 'pixel', 'galaxy', 'oneplus', 'xiaomi', 'oppo',
             'redmi', 'huawei', 'motorola', 'android', '-phone'],
            ['network', 'fa-wifi', 'Network equipment',
             'ubiquiti', 'unifi', 'routerboard', 'mikrotik', 'tp-link', 'avm ',
             'netgear', 'aruba', 'zyxel', 'ruckus', 'cisco'],
            ['vm', 'fa-server', 'Virtual machine',
             'proxmox', 'vmware', 'xensource', 'qemu', 'innotek', 'parallels',
             'oracle virtual'],
            ['server', 'fa-hdd-o', 'Server or appliance',
             'fujitsu', 'supermicro', 'hewlett packard', 'dell inc', 'synology',
             'qnap', 'nas'],
            ['iot', 'fa-lightbulb-o', 'Smart home device',
             'tuya', 'espressif', 'shelly', 'sonoff', 'sonos', 'signify',
             'philips lighting', 'nest', 'ring inc', 'tado'],
            ['computer', 'fa-desktop', 'Computer',
             'asustek', 'micro-star', 'gigabyte', 'lenovo', 'intel corporate',
             'apple', 'realtek', 'azurewave', 'hon hai', 'framework'],
        ];
    }

    /**
     * @param string|null $vendor from the OUI table
     * @param string|null $hostname whatever the device announced
     * @param bool $isLocal an address configured on this firewall
     * @return array ['icon' => fa class, 'type' => human label, 'guessed' => bool]
     */
    public static function of(?string $vendor, ?string $hostname, bool $isLocal): array
    {
        if ($isLocal) {
            /* not a guess: a permanent ARP entry is this box's own address */
            return self::kind('firewall', 'fa-shield', gettext('This firewall'), false);
        }

        $haystack = strtolower(trim(($vendor ?? '') . ' ' . ($hostname ?? '')));

        if ($haystack !== '') {
            foreach (self::rules() as $rule) {
                $key = array_shift($rule);
                $icon = array_shift($rule);
                $label = array_shift($rule);
                foreach ($rule as $needle) {
                    if (strpos($haystack, $needle) !== false) {
                        return self::kind($key, $icon, gettext($label), true);
                    }
                }
            }
        }

        return self::kind('unknown', 'fa-circle-o', gettext('Unrecognised'), false);
    }

    /**
     * The operator's own choice, which is not a guess at all.
     *
     * @param string $key one of choices()
     * @return array|null null when the key is not one this version knows
     */
    public static function chosen(string $key): ?array
    {
        if ($key === 'firewall') {
            return self::kind('firewall', 'fa-shield', gettext('This firewall'), false);
        }

        foreach (self::rules() as $rule) {
            if ($rule[0] === $key) {
                return self::kind($key, $rule[1], gettext($rule[2]), false);
            }
        }

        return null;
    }

    /**
     * @return array key to label, for the picker
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::rules() as $rule) {
            $choices[$rule[0]] = gettext($rule[2]);
        }
        $choices['firewall'] = gettext('This firewall');

        return $choices;
    }

    private static function kind(string $key, string $icon, string $type, bool $guessed): array
    {
        return ['key' => $key, 'icon' => $icon, 'type' => $type, 'guessed' => $guessed];
    }
}
