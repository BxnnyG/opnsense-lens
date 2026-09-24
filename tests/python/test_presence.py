"""
When each device was here: merging a device's windows and clipping them to the
chart. The two ways this goes wrong both look plausible on screen, which is why
they are tested rather than eyeballed.
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

from lenslib import presence                                    # noqa: E402
from lenslib.store import Store                                 # noqa: E402


class SpansTest(unittest.TestCase):
    def test_two_addresses_at_once_are_one_presence_not_two(self):
        """the admin PC in two VLANs is home once"""
        found = presence.spans([
            ('aa', 1000, 5000),
            ('aa', 2000, 6000),
        ], 0, 10000)

        self.assertEqual([[1000, 6000]], found['aa'])
        self.assertEqual(5000, presence.seconds(found['aa']))

    def test_a_window_that_began_before_the_chart_starts_at_its_edge(self):
        found = presence.spans([('aa', 0, 9000)], 5000, 10000)

        self.assertEqual([[5000, 9000]], found['aa'])

    def test_touching_windows_are_continuous(self):
        found = presence.spans([('aa', 1000, 2000), ('aa', 2000, 3000)], 0, 10000)

        self.assertEqual([[1000, 3000]], found['aa'])

    def test_a_real_absence_stays_a_gap(self):
        found = presence.spans([('aa', 1000, 2000), ('aa', 5000, 6000)], 0, 10000)

        self.assertEqual([[1000, 2000], [5000, 6000]], found['aa'])

    def test_a_window_entirely_before_the_chart_is_not_drawn(self):
        self.assertEqual({}, presence.spans([('aa', 100, 200)], 5000, 10000))

    def test_devices_do_not_bleed_into_each_other(self):
        found = presence.spans([('aa', 1000, 2000), ('bb', 1500, 3000)], 0, 10000)

        self.assertEqual([[1000, 2000]], found['aa'])
        self.assertEqual([[1500, 3000]], found['bb'])


class StoreTest(unittest.TestCase):
    def test_only_windows_that_reach_into_the_chart_are_read(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Store(os.path.join(directory, 'lens.sqlite'))
            for mac, first, last in (('aa', 100, 200), ('bb', 100, 9000)):
                store.db.execute(
                    """INSERT INTO address_observation
                       (mac, address, interface, first_seen, last_seen)
                       VALUES (?, '10.0.0.1', 'em0', ?, ?)""", (mac, first, last))
            store.commit()

            macs = {row['mac'] for row in store.presence_windows(5000)}

            self.assertEqual({'bb'}, macs)


class HeatmapStoreTest(unittest.TestCase):
    """the heatmap rows add up to the same bytes as the device's own chart"""

    def test_a_devices_week_totals_its_attributed_traffic(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Store(os.path.join(directory, 'lens.sqlite'))
            base = 1788080400
            store.store_buckets('p', [
                (base, 'em0', '10.0.0.5', 'in', 100, 1),
                (base + 3600, 'em0', '10.0.0.5', 'out', 400, 1),
                (base, 'pppoe0', '1.1.1.1', 'out', 9000, 1),
            ])
            store.db.execute(
                """INSERT INTO address_observation
                   (mac, address, interface, first_seen, last_seen)
                   VALUES ('aa', '10.0.0.5', 'em0', ?, ?)""", (base - 3600, base + 9000))
            store.commit()

            week = sum(r['octets'] for r in store.device_heatmap('aa', 0))
            chart = sum(r['octets'] for r in store.device_traffic('aa', 0))
            network = sum(r['octets'] for r in store.network_heatmap(0))

            self.assertEqual(500, week)
            self.assertEqual(chart, week)
            self.assertEqual(500, network, 'the far end is not your network')

            cells = list(store.device_heatmap('aa', 0))
            self.assertTrue(all(0 <= r['dow'] <= 6 and 0 <= r['hour'] <= 23 for r in cells))


if __name__ == '__main__':
    unittest.main()
