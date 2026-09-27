"""
A device folded from rotating MACs (§4.61) is asked about all of them at once.
The union may only add: every bucket still resolved to exactly one MAC in the
attribution join, so the folded chart is the per-MAC charts stacked, never more.
"""

import os
import sys
import tempfile
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

import collect                                                  # noqa: E402
from lenslib import settings                                    # noqa: E402
from lenslib.store import Store                                 # noqa: E402

HOUR = 3600
T0 = 1789992000 - 1789992000 % HOUR
OLD, NEW = 'e6:00:00:00:00:01', 'e6:00:00:00:00:02'


class FoldQueriesTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        for mac, address, start in ((OLD, '10.10.20.61', T0), (NEW, '10.10.20.62', T0 + 5 * HOUR)):
            self.store.see_device(mac, start, True, False, 'Pixel-8', 'dnsmasq')
            self.store.db.execute(
                "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
                " VALUES (?, ?, 'vtnet1_vlan20', ?, ?)", (mac, address, start, start + 3 * HOUR))
        self.store.store_buckets('FlowSourceAddrTotals', [
            (T0, 'vtnet1_vlan20', '10.10.20.61', 'in', 100, 1),
            (T0 + HOUR, 'vtnet1_vlan20', '10.10.20.61', 'out', 1000, 1),
            (T0 + 5 * HOUR, 'vtnet1_vlan20', '10.10.20.62', 'in', 7, 1),
        ])
        self.store.commit()

    def tearDown(self):
        self.dir.cleanup()

    def total(self, rows):
        return sum(row['octets'] for row in rows)

    def test_the_folded_chart_is_the_sum_of_its_macs(self):
        both = self.total(self.store.device_traffic([OLD, NEW], T0 - HOUR))
        apart = self.total(self.store.device_traffic(OLD, T0 - HOUR)) \
            + self.total(self.store.device_traffic(NEW, T0 - HOUR))

        self.assertEqual(1107, both)
        self.assertEqual(apart, both)

    def test_one_mac_as_a_string_still_works(self):
        self.assertEqual(1100, self.total(self.store.device_traffic(OLD, T0 - HOUR)))

    def test_the_device_duty_takes_a_comma_separated_list(self):
        report = collect.device(self.store, T0 + 10 * HOUR, OLD + ',' + NEW, 24)

        self.assertEqual(107, report['sent'])
        self.assertEqual(1000, report['received'])
        self.assertEqual(['vtnet1_vlan20'], report['interfaces'])

    def test_where_it_has_been_lists_every_mac_windows(self):
        profile = collect.profile(self.store, T0 + 10 * HOUR, OLD + ',' + NEW)

        self.assertEqual(2, len(profile['windows']))
        self.assertEqual([OLD, NEW], profile['macs'])

    def test_folding_is_on_by_default_and_can_be_switched_off(self):
        self.assertTrue(settings.load({})['fold_randomised'])
        self.assertTrue(self.store.status()['fold_randomised'])
        clean, errors = settings.validate({'fold_randomised': '0'})
        self.assertEqual({}, errors)
        self.store.set_settings(clean)
        self.assertFalse(self.store.status()['fold_randomised'])


if __name__ == '__main__':
    unittest.main()
