<?php

use OPNsense\Lens\Health;
use OPNsense\Lens\Tunnels;
use PHPUnit\Framework\TestCase;

/**
 * Stage 59: OpenVPN, IPsec and CARP -- what is configured against what runs,
 * and only what should be up on its own is judged.
 */
class TunnelsTest extends TestCase
{
    private const CONFIG = <<<'XML'
<opnsense>
  <interfaces><lan><if>igb1</if><descr>HOME</descr></lan><opt1><if>igb2</if><descr>IOT</descr></opt1></interfaces>
  <openvpn>
    <openvpn-server><vpnid>1</vpnid><description>Road warriors</description></openvpn-server>
    <openvpn-server><vpnid>2</vpnid><description>Old one</description><disable>1</disable></openvpn-server>
  </openvpn>
  <OPNsense>
    <OpenVPN><Instances>
      <Instance uuid="aaaaaaaa-0000-4000-8000-000000000001"><vpnid>3</vpnid><enabled>1</enabled><role>client</role><description>To the office</description></Instance>
      <Instance uuid="aaaaaaaa-0000-4000-8000-000000000002"><vpnid>4</vpnid><enabled>0</enabled><role>client</role><description>Off</description></Instance>
    </Instances></OpenVPN>
    <Swanctl>
      <Connections>
        <Connection uuid="bbbbbbbb-0000-4000-8000-000000000001"><enabled>1</enabled><remote_addrs>198.51.100.7</remote_addrs><description>Branch</description></Connection>
        <Connection uuid="bbbbbbbb-0000-4000-8000-000000000002"><enabled>1</enabled><remote_addrs>198.51.100.8</remote_addrs><description>On demand</description></Connection>
        <Connection uuid="bbbbbbbb-0000-4000-8000-000000000003"><enabled>1</enabled><remote_addrs></remote_addrs><description>Phones</description></Connection>
      </Connections>
      <children>
        <child uuid="c1"><enabled>1</enabled><connection>bbbbbbbb-0000-4000-8000-000000000001</connection><start_action>trap|start</start_action></child>
        <child uuid="c2"><enabled>1</enabled><connection>bbbbbbbb-0000-4000-8000-000000000002</connection><start_action>trap</start_action></child>
      </children>
    </Swanctl>
  </OPNsense>
  <ipsec><enable>1</enable><phase1><ikeid>5</ikeid><descr>Legacy site</descr><remote-gateway>203.0.113.9</remote-gateway></phase1></ipsec>
  <virtualip>
    <vip uuid="v1"><mode>carp</mode><vhid>1</vhid><subnet>192.0.2.1</subnet><interface>lan</interface></vip>
    <vip uuid="v2"><mode>carp</mode><vhid>2</vhid><subnet>192.0.2.65</subnet><interface>opt1</interface></vip>
    <vip uuid="v3"><mode>ipalias</mode><vhid></vhid><subnet>192.0.2.200</subnet><interface>lan</interface></vip>
  </virtualip>
</opnsense>
XML;

    private function config(): \SimpleXMLElement
    {
        return new \SimpleXMLElement(self::CONFIG);
    }

    public function testOnlyEnabledOpenvpnInstancesCountLegacyByVpnidCurrentByUuid()
    {
        $this->assertSame([
            ['1', 'server', 'Road warriors'],
            ['aaaaaaaa-0000-4000-8000-000000000001', 'client', 'To the office'],
        ], Tunnels::openvpnConfigured($this->config()));
    }

    public function testAServerNobodyUsesIsFineAClientNotThroughIsNot()
    {
        $configured = Tunnels::openvpnConfigured($this->config());
        $rows = Tunnels::openvpn([
            'server' => ['1' => ['status' => 'ok']],
            'client' => ['aaaaaaaa-0000-4000-8000-000000000001' => ['status' => 'reconnecting']],
        ], $configured);
        $this->assertSame(['good', 'warn'], array_column($rows, 'tone'));
        $tile = Health::openvpn($rows);
        $this->assertSame('warn', $tile['tone']);
        $this->assertSame('To the office: reconnecting.', $tile['sentence']);
    }

    public function testConnectedClientsAreListedUnderTheirServer()
    {
        $rows = Tunnels::openvpn([
            'server' => ['1' => ['status' => 'ok', 'client_list' => [
                ['common_name' => 'laptop', 'virtual_address' => '10.8.0.2', 'real_address' => '198.51.100.20:1194'],
            ]]],
            'client' => ['aaaaaaaa-0000-4000-8000-000000000001' => ['status' => 'connected', 'virtual_address' => '10.9.0.2']],
        ], Tunnels::openvpnConfigured($this->config()));
        $this->assertCount(3, $rows);
        $tile = Health::openvpn($rows);
        $this->assertSame('good', $tile['tone']);
        $this->assertSame('Every instance runs; clients connected: 1.', $tile['sentence']);
    }

    public function testAnInstanceWithoutASocketIsNotRunning()
    {
        $tile = Health::openvpn(Tunnels::openvpn([], Tunnels::openvpnConfigured($this->config())));
        $this->assertSame('bad', $tile['tone']);
        $this->assertStringStartsWith('Road warriors: not running.', $tile['sentence']);
        $this->assertSame('grey', Health::openvpn(null)['tone']);
    }

    public function testIpsecExpectsOnlyWhatStartsOnItsOwn()
    {
        $configured = Tunnels::ipsecConfigured($this->config());
        $expected = array_combine(array_column($configured, 1), array_column($configured, 3));
        $this->assertSame(
            ['Legacy site' => true, 'Branch' => true, 'On demand' => false, 'Phones' => false],
            $expected
        );
    }

    public function testATunnelThatWaitsForTrafficIsNoFaultABranchDownIs()
    {
        $configured = Tunnels::ipsecConfigured($this->config());
        $rows = Tunnels::ipsec([
            'con5-000' => ['sas' => [['state' => 'ESTABLISHED']]],
            'bbbbbbbb-0000-4000-8000-000000000001' => ['sas' => []],
            'bbbbbbbb-0000-4000-8000-000000000003' => ['sas' => [[], []]],
        ], $configured);
        $this->assertSame(['good', 'warn', 'grey', 'good'], array_column($rows, 'tone'));
        $this->assertSame('2 connected', $rows[3]['state']);
        $tile = Health::ipsec($rows, true);
        $this->assertSame('warn', $tile['tone']);
        $this->assertSame('Branch: not connected.', $tile['sentence']);
    }

    public function testIpsecSwitchedOnButNotRunningIsBad()
    {
        $this->assertSame('bad', Health::ipsec(null, false)['tone']);
    }

    private function ifconfig(string $first, string $second): array
    {
        return [
            'igb1' => ['carp' => ['1' => ['status' => $first, 'vhid' => '1']],
                       'ipv4' => [['ipaddr' => '192.0.2.1', 'vhid' => '1'], ['ipaddr' => '192.0.2.2']]],
            'igb2' => ['carp' => ['2' => ['status' => $second, 'vhid' => '2']],
                       'ipv4' => [['ipaddr' => '192.0.2.65', 'vhid' => '2']]],
            'igb3' => ['ipv4' => [['ipaddr' => '192.0.2.129']]],
        ];
    }

    public function testMasterForEverythingOrBackupForEverythingIsGood()
    {
        $vips = Tunnels::carpConfigured($this->config());
        $names = ['igb1' => 'HOME', 'igb2' => 'IOT'];
        $master = Health::carp(Tunnels::carp($this->ifconfig('MASTER', 'MASTER'), $vips, $names));
        $this->assertSame('good', $master['tone']);
        $this->assertSame('Master for every virtual address (2).', $master['sentence']);
        $backup = Health::carp(Tunnels::carp($this->ifconfig('BACKUP', 'BACKUP'), $vips, $names));
        $this->assertSame('good', $backup['tone']);
        $this->assertStringStartsWith('Backup for every virtual address (2)', $backup['sentence']);
    }

    public function testASplitIsWorthALookAndInitIsBad()
    {
        $vips = Tunnels::carpConfigured($this->config());
        $names = ['igb1' => 'HOME', 'igb2' => 'IOT'];
        $split = Health::carp(Tunnels::carp($this->ifconfig('MASTER', 'BACKUP'), $vips, $names));
        $this->assertSame('warn', $split['tone']);
        $this->assertStringStartsWith('Split: master for 1, backup for 1 virtual addresses.', $split['sentence']);
        $init = Health::carp(Tunnels::carp($this->ifconfig('MASTER', 'INIT'), $vips, $names));
        $this->assertSame('bad', $init['tone']);
        $this->assertSame('IOT, VHID 2: INIT.', $init['sentence']);
    }

    public function testAConfiguredAddressTheKernelDoesNotListIsDisabled()
    {
        $rows = Tunnels::carp([], Tunnels::carpConfigured($this->config()), []);
        $this->assertSame(['DISABLED', 'DISABLED'], array_column($rows, 'state'));
        $this->assertSame('HOME, VHID 1', $rows[0]['name']);
        $this->assertSame('warn', Health::carp($rows)['tone']);
    }

    public function testNoCarpConfiguredMeansNoRows()
    {
        $this->assertSame([], Tunnels::carp(['igb3' => ['ipv4' => [['ipaddr' => '192.0.2.129']]]], [], []));
    }
}
