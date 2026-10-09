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
use OPNsense\Lens\Health;
use PHPUnit\Framework\TestCase;

/**
 * Is everything all right (§4.84, stage 50): every tone of every tile, and a
 * row that says "all is well" only when it is.
 */
class HealthTest extends TestCase
{
    private const NOW = 1791500000;

    private function facts(int $disk = 40, int $memory = 50, float $load = 0.4): array
    {
        return ['disk' => ['percent' => $disk], 'memory' => ['percent' => $memory],
                'load' => ['fifteen' => $load, 'cores' => 4], 'uptime' => ['text' => '3 days']];
    }

    public function testTheSystemTileGoesByDiskThenMemoryThenLoad()
    {
        $this->assertSame('good', Health::system($this->facts())['tone']);
        $this->assertSame('warn', Health::system($this->facts(86))['tone']);
        $bad = Health::system($this->facts(96));
        $this->assertSame('bad', $bad['tone']);
        $this->assertStringContainsString('96%', $bad['sentence']);
        $this->assertSame('warn', Health::system($this->facts(40, 92))['tone']);
        $this->assertSame('warn', Health::system($this->facts(40, 50, 7.0))['tone']);
        $this->assertSame('grey', Health::system(null)['tone']);
        $this->assertContains('up 3 days', Health::system($this->facts())['detail']);
    }

    public function testTemperatureIsTheHottestSensorAndNoSensorsIsNoTile()
    {
        $this->assertNull(Health::temperature([]));
        $this->assertSame('grey', Health::temperature(null)['tone']);
        $tile = Health::temperature(['dev.cpu.0.temperature' => '48.0C', 'dev.cpu.1.temperature' => '83.0C']);
        $this->assertSame('warn', $tile['tone']);
        $this->assertStringContainsString('83', $tile['sentence']);
        $this->assertSame(['CPU 1: 83 °C'], $tile['detail']);
        $this->assertSame('bad', Health::temperature(['hw.acpi.thermal.tz0.temperature' => '95.1C'])['tone']);
        $this->assertSame('good', Health::temperature(['dev.cpu.0.temperature' => '41.0C'])['tone']);
        // a nonsense reading is not the hottest
        $this->assertSame('good', Health::temperature(['a' => '41.0C', 'b' => '-273.0C', 'c' => '900C'])['tone']);
    }

    public function testUpdatesAreReadNeverChecked()
    {
        $this->assertSame('grey', Health::updates(null)['tone']);
        $this->assertSame('No check for updates on record.', Health::updates(['product_check' => null])['sentence']);
        $this->assertSame('warn', Health::updates(['product_check' => ['connection' => 'unresolved']])['tone']);
        $this->assertSame('good', Health::updates(['product_version' => '26.7.2', 'product_check' => [
            'connection' => 'ok', 'repository' => 'ok', 'upgrade_packages' => []]])['tone']);
        $two = Health::updates(['product_check' => ['connection' => 'ok', 'repository' => 'ok',
            'upgrade_packages' => [['name' => 'a'], ['name' => 'b']], 'needs_reboot' => 1]]);
        $this->assertSame('warn', $two['tone']);
        $this->assertSame('2 updates are waiting. It needs a reboot.', $two['sentence']);
        $sets = Health::updates(['product_check' => ['upgrade_sets' => [['name' => 'base']]]]);
        $this->assertSame('One update is waiting.', $sets['sentence']);
    }

    public function testAServiceThatIsOnAndNotRunningIsBadAndUncheckedOnesDoNotCount()
    {
        $this->assertSame('grey', Health::services(null)['tone']);
        $list = [
            ['name' => 'unbound', 'description' => 'Unbound DNS', 'status' => 'unbound is running as pid 1.'],
            ['name' => 'configd', 'description' => 'System Configuration Daemon', 'status' => '', 'locked' => 1],
            ['name' => 'ntpd', 'description' => 'Network Time Daemon', 'status' => 'ntpd is not running.'],
        ];
        $tile = Health::services($list);
        $this->assertSame('bad', $tile['tone']);
        $this->assertSame('Network Time Daemon is not running.', $tile['sentence']);
        $this->assertSame('good', Health::services([$list[0], $list[1]])['tone']);
        $this->assertSame('All 1 services are running.', Health::services([$list[0]])['sentence']);
    }

    public function testCertificatesInUseWarnBeforeTheyLapseAndUnusedOnesAreOnlyCounted()
    {
        $day = 86400;
        $this->assertNull(Health::certificates([], self::NOW));
        $this->assertSame('grey', Health::certificates(null, self::NOW)['tone']);
        $good = Health::certificates([['name' => 'web', 'expires' => self::NOW + 60 * $day, 'in_use' => true]],
            self::NOW);
        $this->assertSame('good', $good['tone']);
        $warn = Health::certificates([
            ['name' => 'web', 'expires' => self::NOW + 60 * $day, 'in_use' => true],
            ['name' => 'vpn', 'expires' => self::NOW + 10 * $day, 'in_use' => true],
            ['name' => 'old', 'expires' => self::NOW - $day, 'in_use' => false],
        ], self::NOW);
        $this->assertSame('warn', $warn['tone']);
        $this->assertSame('vpn expires in 10 days.', $warn['sentence']);
        $this->assertContains('1 expired, unused', $warn['detail']);
        $this->assertSame('bad', Health::certificates([['name' => 'vpn', 'expires' => self::NOW + $day,
            'in_use' => true]], self::NOW)['tone']);
        $this->assertSame('vpn has expired.', Health::certificates([['name' => 'vpn',
            'expires' => self::NOW - 1, 'in_use' => true]], self::NOW)['sentence']);
        $this->assertSame('No certificate is in use.', Health::certificates([['name' => 'old',
            'expires' => self::NOW - 1, 'in_use' => false]], self::NOW)['sentence']);
    }

    public function testACertificateIsReadFromItsPublicPartOnly()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'fw.example'], $key);
        $crt = openssl_csr_sign($csr, null, $key, 30);
        openssl_x509_export($crt, $pem);

        $parsed = Health::certificate(base64_encode($pem), '');
        $this->assertSame('fw.example', $parsed['name']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $parsed['expires'], 86400 * 1.5);
        $this->assertSame('Web GUI', Health::certificate(base64_encode($pem), 'Web GUI')['name']);
        $this->assertNull(Health::certificate('not base64 !!', 'x'));
        $this->assertNull(Health::certificate(base64_encode('not a certificate'), 'x'));
    }

    public function testTheInternetTileTakesInternetsOwnVerdict()
    {
        $this->assertSame('good', Health::internet(['state' => ['key' => 'up', 'text' => 'Online'],
            'uptime' => ['percent' => 100]])['tone']);
        $this->assertSame('bad', Health::internet(['state' => ['key' => 'down', 'text' => 'Offline']])['tone']);
        $this->assertSame('grey', Health::internet(['state' => ['key' => 'unknown', 'text' => '?']])['tone']);
        $this->assertSame('grey', Health::internet(null)['tone']);
    }

    public function testTheRowSaysAllIsWellOnlyWhenEveryTileIsGood()
    {
        $good = Health::system($this->facts());
        $this->assertSame('good', Health::summary([$good, $good])['tone']);

        $grey = Health::updates(['product_check' => null]);
        $summary = Health::summary([$good, $grey]);
        $this->assertSame('grey', $summary['tone']);
        $this->assertSame('Nothing is wrong that Lens can see. Updates: No check for updates on record.',
            $summary['sentence']);

        $warn = Health::system($this->facts(88));
        $bad = Health::services([['name' => 'ntpd', 'description' => 'NTP', 'status' => 'stopped']]);
        $summary = Health::summary([$good, $warn, $grey, $bad]);
        $this->assertSame('bad', $summary['tone']);
        $this->assertSame('NTP is not running. And 1 more need a look.', $summary['sentence']);
        $this->assertSame('/ui/core/service', $summary['link']);
    }

    public function testACertificateIsUsedOnlyByWhatIsSwitchedOn()
    {
        $config = simplexml_load_string('<?xml version="1.0"?><opnsense>
            <system><webgui><ssl-certref>aaaaaaaaaaaaa</ssl-certref></webgui>
                <user><name>anna</name><cert>ccccccccccccc</cert></user></system>
            <OPNsense><AcmeClient><certificates>
                <certificate><enabled>1</enabled><certRefId>bbbbbbbbbbbbb</certRefId></certificate>
                <certificate><enabled>0</enabled><certRefId>ddddddddddddd</certRefId></certificate>
            </certificates></AcmeClient></OPNsense>
            <cert><refid>aaaaaaaaaaaaa</refid></cert><cert><refid>bbbbbbbbbbbbb</refid></cert>
            <cert><refid>ccccccccccccc</refid></cert><cert><refid>ddddddddddddd</refid></cert>
        </opnsense>');

        $this->assertSame(['system.webgui'], Health::usersOf($config, 'aaaaaaaaaaaaa'));
        $this->assertSame(['AcmeClient'], Health::usersOf($config, 'bbbbbbbbbbbbb'));
        // a user's own certificate, as core's rule says, and a disabled ACME entry, as Lens's adds
        $this->assertSame([], Health::usersOf($config, 'ccccccccccccc'));
        $this->assertSame([], Health::usersOf($config, 'ddddddddddddd'));
        $this->assertSame([], Health::usersOf($config, "x' or '1'='1"));
    }

    public function testTheCertificateTileSaysWhoUsesTheFirstToExpire()
    {
        $tile = Health::certificates([['name' => 'portal', 'expires' => self::NOW - 1, 'in_use' => true,
            'users' => ['AcmeClient']]], self::NOW);
        $this->assertContains('portal used by AcmeClient', $tile['detail']);
    }

    public function testDynDnsNamesAreComparedWithThePublicAddress()
    {
        $accounts = [
            ['uuid' => 'u1', 'description' => 'home', 'hostnames' => 'home.example.org', 'enabled' => true],
            ['uuid' => 'u2', 'description' => 'old', 'hostnames' => 'old.example.org', 'enabled' => false],
        ];
        $public = ['v4' => '93.239.95.224', 'v6' => null];

        // the native backend keys by uuid
        $good = Health::dyndns($accounts, ['hosts' => ['u1' => ['ip' => '93.239.95.224', 'mtime' => 1]]], $public);
        $this->assertSame('good', $good['tone']);
        $this->assertSame('home.example.org points at this firewall.', $good['sentence']);
        // ddclient keys by hostname
        $astray = Health::dyndns($accounts, ['hosts' => ['home.example.org' => ['ip' => '84.1.2.3']]], $public);
        $this->assertSame('warn', $astray['tone']);
        $this->assertSame('home.example.org points at 84.1.2.3, but this firewall is 93.239.95.224.', $astray['sentence']);
        $this->assertSame('warn', Health::dyndns($accounts, ['hosts' => []], $public)['tone']);
        $this->assertSame('grey', Health::dyndns($accounts, ['hosts' => []], [])['tone']);
        // nothing enabled, nothing to say
        $this->assertNull(Health::dyndns([$accounts[1]], [], $public));
        $this->assertSame('grey', Health::dyndns(null, null, $public)['tone']);
    }

    public function testSmartTakesTheDisksOwnVerdict()
    {
        $ok = ['device' => 'ada0', 'ident' => 'S3Z', 'state' => ['smart_status' => ['passed' => true]]];
        $bad = ['device' => 'ada1', 'ident' => 'WD1', 'state' => ['smart_status' => ['passed' => false]]];
        $mute = ['device' => 'da0', 'ident' => 'USB', 'state' => ['smartctl' => ['exit_status' => 4]]];

        $this->assertSame('All 2 disks report healthy.', Health::smart([$ok, $ok])['sentence']);
        $failing = Health::smart([$ok, $bad, $mute]);
        $this->assertSame('bad', $failing['tone']);
        $this->assertSame('ada1 WD1 reports that it is failing.', $failing['sentence']);
        $this->assertContains('1 without SMART: da0 USB', $failing['detail']);
        $this->assertSame('grey', Health::smart([$mute])['tone']);
        $this->assertNull(Health::smart([]));
        $this->assertSame('grey', Health::smart(null)['tone']);
    }

    public function testWireGuardSaysWhoIsConnectedNotWhetherItIsAFault()
    {
        $records = [
            ['type' => 'interface', 'if' => 'wg0'],
            ['type' => 'peer', 'if' => 'wg0', 'public-key' => 'KEYANNA', 'latest-handshake' => self::NOW - 40],
            ['type' => 'peer', 'if' => 'wg0', 'public-key' => 'KEYBOB', 'latest-handshake' => self::NOW - 4000],
            ['type' => 'peer', 'if' => 'wg0', 'public-key' => 'KEYCARLXYZ', 'latest-handshake' => 0],
        ];
        $tile = Health::wireguard($records, ['KEYANNA' => 'Anna phone'], self::NOW);
        $this->assertSame('good', $tile['tone']);
        $this->assertSame('1 of 3 peers connected now.', $tile['sentence']);
        $this->assertSame(['Anna phone'], $tile['detail']);
        $this->assertSame('None of 1 peers connected now.',
            Health::wireguard([$records[2]], [], self::NOW)['sentence']);
        $this->assertNull(Health::wireguard([$records[0]], [], self::NOW));
        $this->assertSame('grey', Health::wireguard(null, [], self::NOW)['tone']);
    }
}
