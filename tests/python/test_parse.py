"""
The collector's decisions, against output the operator's own router produced.

Run:  python3 -m unittest discover -s tests/python
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
))

from lenslib import parse                                       # noqa: E402

ARP = """? (192.168.101.2) at bc:24:11:21:6c:f3 on vtnet2 permanent [ethernet]
? (10.10.10.1) at bc:24:11:3e:e7:28 on vtnet1_vlan10 permanent [vlan]
? (10.10.10.141) at 42:c5:38:e1:54:c7 on vtnet1_vlan10 expires in 1162 seconds [vlan]
? (10.10.20.136) at 42:C5:38:E1:54:C7 on vtnet1_vlan20 expires in 841 seconds [vlan]
"""

NDP = """Neighbor                             Linklayer Address  Netif Expire    S Flags
fe80::1%vtnet1_vlan10                bc:24:11:3e:e7:28 vtnet1_vlan10 23h59m48s S R
2003:e8:6fff:2ac5:be24:11ff:fe21:6cf3 (incomplete)     pppoe0 permanent R
"""

LEASES = """1788160701 42:c5:38:e1:54:c7 10.10.10.141 bxy-cachyos-x8664 01:42:c5:38:e1:54:c7
1788157800 cc:8c:bf:9b:d3:56 10.10.20.199 wlan0 *
1788156957 cc:8c:bf:9c:0d:fc 10.10.20.168 * *
"""


class ParseTest(unittest.TestCase):
    def test_arp_is_read_and_the_firewalls_own_rows_are_marked(self):
        rows = parse.parse_arp(ARP)

        self.assertEqual(4, len(rows))
        self.assertIn(('42:c5:38:e1:54:c7', '10.10.10.141', 'vtnet1_vlan10', False), rows)
        self.assertIn(('bc:24:11:3e:e7:28', '10.10.10.1', 'vtnet1_vlan10', True), rows)

    def test_the_same_mac_in_two_spellings_is_one_device(self):
        macs = {row[0] for row in parse.parse_arp(ARP)}

        self.assertIn('42:c5:38:e1:54:c7', macs)
        self.assertNotIn('42:C5:38:E1:54:C7', macs)

    def test_incomplete_ndp_entries_are_not_devices(self):
        rows = parse.parse_ndp(NDP)

        self.assertEqual([('bc:24:11:3e:e7:28', 'fe80::1', 'vtnet1_vlan10')], rows)

    def test_leases_that_name_nothing_are_not_names(self):
        names = parse.parse_dnsmasq_leases(LEASES)

        self.assertEqual('bxy-cachyos-x8664', names['42:c5:38:e1:54:c7'])
        self.assertEqual('wlan0', names['cc:8c:bf:9b:d3:56'])
        self.assertNotIn('cc:8c:bf:9c:0d:fc', names)

    def test_kea_leases_are_read_from_csv(self):
        text = ('address,hwaddr,client_id,valid_lifetime,expire,subnet_id,'
                'fqdn_fwd,fqdn_rev,hostname,state\n'
                '10.10.20.5,AA:BB:CC:DD:EE:FF,,3600,1788160701,1,0,0,printer,0\n'
                '10.10.20.6,aa:bb:cc:dd:ee:01,,3600,1788160701,1,0,0,,0\n')
        names = parse.parse_kea_leases(text)

        self.assertEqual({'aa:bb:cc:dd:ee:ff': 'printer'}, names)

    def test_randomised_macs_are_recognised(self):
        self.assertTrue(parse.is_randomised('42:c5:38:e1:54:c7'))
        self.assertTrue(parse.is_randomised('8e:e0:19:d4:f7:55'))
        self.assertFalse(parse.is_randomised('bc:24:11:3e:e7:28'))
        self.assertFalse(parse.is_randomised('nonsense'))


class FoldObservationsTest(unittest.TestCase):
    KEY = ('42:c5:38:e1:54:c7', '10.10.10.141', 'vtnet1_vlan10')
    OTHER = ('42:c5:38:e1:54:c7', '10.10.20.136', 'vtnet1_vlan20')

    def test_a_first_sighting_opens_a_window(self):
        extend, opened = parse.fold_observations([self.KEY], {}, 1000, 900)

        self.assertEqual([], extend)
        self.assertEqual([self.KEY], opened)

    def test_a_recent_window_is_extended_not_reopened(self):
        extend, opened = parse.fold_observations([self.KEY], {self.KEY: 700}, 1000, 900)

        self.assertEqual([self.KEY], extend)
        self.assertEqual([], opened)

    def test_a_device_that_was_away_gets_a_new_window(self):
        """the difference is the whole point: traffic is attributed to the window
        that covers its timestamp, not to a device that once held the address"""
        extend, opened = parse.fold_observations([self.KEY], {self.KEY: 10}, 1000, 900)

        self.assertEqual([], extend)
        self.assertEqual([self.KEY], opened)

    def test_two_concurrent_addresses_are_two_windows_not_a_flap(self):
        """the operator's admin PC is in MGNT and HOME at once (4.17)"""
        extend, opened = parse.fold_observations(
            [self.KEY, self.OTHER], {self.KEY: 900, self.OTHER: 900}, 1000, 900
        )

        self.assertEqual(2, len(extend))
        self.assertEqual([], opened)

    def test_the_same_sighting_twice_in_one_run_is_one_decision(self):
        extend, opened = parse.fold_observations([self.KEY, self.KEY], {}, 1000, 900)

        self.assertEqual([self.KEY], opened)


class BucketsTest(unittest.TestCase):


    def payload(self):
        return {
            '1787990400': {'10.10.20.115,in': {'octets': 120, 'packets': 3},
                           '10.10.20.115,out': {'octets': 900, 'packets': 7}},
            '1787994000': {'10.10.20.115,in': {'octets': 0, 'packets': 0}},
            '1787997600': {'10.10.20.199,in': {'octets': 55, 'packets': 1}},
        }

    def test_zero_filler_slices_are_not_measurements(self):
        """a device that sent nothing produced no record, it did not send 0 bytes"""
        rows = parse.buckets_from_timeseries(self.payload(), complete_before=1788001200)

        self.assertEqual(3, len(rows))
        self.assertNotIn(1787994000, [row[0] for row in rows])

    def test_the_hour_still_being_written_is_not_stored(self):
        """storing a partial hour and never revisiting it would freeze it"""
        rows = parse.buckets_from_timeseries(self.payload(), complete_before=1787997600)

        self.assertEqual([1787990400, 1787990400], [row[0] for row in rows])

    def test_buckets_already_held_are_not_offered_again(self):
        rows = parse.buckets_from_timeseries(
            self.payload(), complete_before=1788001200, after=1787990400
        )

        self.assertEqual([1787997600], [row[0] for row in rows])

    def test_rows_carry_address_and_direction_apart(self):
        rows = parse.buckets_from_timeseries(self.payload(), complete_before=1788001200)

        self.assertIn((1787990400, '10.10.20.115', 'out', 900, 7), rows)

    def test_nonsense_is_survived_rather_than_raised(self):
        self.assertEqual([], parse.buckets_from_timeseries({}, complete_before=1))
        self.assertEqual([], parse.buckets_from_timeseries(None, complete_before=1))
        self.assertEqual([], parse.buckets_from_timeseries(
            {'not-a-timestamp': {'x,in': {'octets': 5}}}, complete_before=999999999999
        ))
        self.assertEqual([], parse.buckets_from_timeseries(
            {'1787990400': {',in': {'octets': 5, 'packets': 1}}}, complete_before=999999999999
        ))


if __name__ == '__main__':
    unittest.main()
