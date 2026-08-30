"""
Traffic to devices, at the time of the bucket.

The three unattributable classes are not edge cases here. On the operator's
router 91% of harvested rows are the far end of a flow -- the internet address,
written by the aggregator's second pass -- and a top-talkers view that silently
dropped them would look right while hiding nine tenths of its input.
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

from lenslib import attribute                                   # noqa: E402
from lenslib.store import Store                                 # noqa: E402

HOUR = 3600
DEVICE_IFS = {'vtnet1_vlan10', 'vtnet1_vlan20'}


def row(interface, address, direction, octets, macs, mac, bucket=1788080000):
    return (bucket, interface, address, direction, octets, 1, macs, mac)


class ClassifyTest(unittest.TestCase):
    def test_a_bucket_with_one_holder_goes_to_that_device(self):
        per_mac, _ = attribute.classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 1000, 1, 'aa:bb:cc:dd:ee:01'),
            row('vtnet1_vlan10', '10.10.10.5', 'out', 4000, 1, 'aa:bb:cc:dd:ee:01'),
        ], DEVICE_IFS)

        self.assertEqual(1000, per_mac['aa:bb:cc:dd:ee:01']['in']['octets'])
        self.assertEqual(4000, per_mac['aa:bb:cc:dd:ee:01']['out']['octets'])

    def test_the_far_end_of_a_flow_is_never_a_device(self):
        """91% of the operator's rows; the internet is not on his network"""
        per_mac, unattributed = attribute.classify([
            row('pppoe0', '142.250.185.78', 'out', 900, 0, None),
        ], DEVICE_IFS)

        self.assertEqual({}, per_mac)
        self.assertEqual(900, unattributed['far_end']['octets'])

    def test_an_address_nobody_was_seen_holding_is_named_not_dropped(self):
        _, unattributed = attribute.classify([
            row('vtnet1_vlan10', '10.10.10.77', 'in', 700, 0, None),
        ], DEVICE_IFS)

        self.assertEqual(700, unattributed['unknown']['octets'])

    def test_an_hour_two_devices_shared_is_refused_rather_than_guessed(self):
        per_mac, unattributed = attribute.classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 500, 2, 'aa:bb:cc:dd:ee:01'),
        ], DEVICE_IFS)

        self.assertEqual({}, per_mac)
        self.assertEqual(500, unattributed['ambiguous']['octets'])

    def test_every_octet_lands_somewhere(self):
        rows = [
            row('vtnet1_vlan10', '10.10.10.5', 'in', 100, 1, 'aa:bb:cc:dd:ee:01'),
            row('pppoe0', '1.1.1.1', 'out', 200, 0, None),
            row('vtnet1_vlan20', '10.10.20.9', 'in', 300, 0, None),
            row('vtnet1_vlan20', '10.10.20.8', 'in', 400, 3, 'aa:bb:cc:dd:ee:02'),
        ]
        per_mac, unattributed = attribute.classify(rows, DEVICE_IFS)

        counted = sum(
            direction['octets'] for device in per_mac.values() for direction in device.values()
        ) + sum(reason['octets'] for reason in unattributed.values())

        self.assertEqual(1000, counted)


class JoinTest(unittest.TestCase):
    """the SQL half: which observation covers which bucket"""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))

    def tearDown(self):
        self.dir.cleanup()

    def observe(self, mac, address, interface, first_seen, last_seen):
        self.store.db.execute(
            """INSERT INTO address_observation
               (mac, address, interface, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)""",
            (mac, address, interface, first_seen, last_seen),
        )

    def test_a_window_that_opened_mid_hour_still_covers_that_hour(self):
        """buckets are hourly, observations are five-minutely; overlap, not containment"""
        self.store.store_buckets('p', [(1788080400, 'em0', '10.0.0.5', 'in', 100, 1)])
        self.observe('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', 1788080400 + 1900, 1788084000)
        self.store.commit()

        rows = list(self.store.traffic_rows(0))

        self.assertEqual(1, rows[0]['macs'])
        self.assertEqual('aa:bb:cc:dd:ee:01', rows[0]['mac'])

    def test_a_window_that_closed_before_the_hour_does_not_cover_it(self):
        self.store.store_buckets('p', [(1788080400, 'em0', '10.0.0.5', 'in', 100, 1)])
        self.observe('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', 1788000000, 1788080000)
        self.store.commit()

        rows = list(self.store.traffic_rows(0))

        self.assertEqual(0, rows[0]['macs'])

    def test_the_address_that_changed_hands_is_attributed_to_who_held_it_then(self):
        """the whole reason the join is on time and not on the current lease"""
        self.store.store_buckets('p', [
            (1788080400, 'em0', '10.0.0.5', 'in', 100, 1),
            (1788084000, 'em0', '10.0.0.5', 'in', 900, 1),
        ])
        self.observe('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', 1788076800, 1788082000)
        self.observe('aa:bb:cc:dd:ee:02', '10.0.0.5', 'em0', 1788084000, 1788090000)
        self.store.commit()

        by_bucket = {row['bucket']: row['mac'] for row in self.store.traffic_rows(0)}

        self.assertEqual('aa:bb:cc:dd:ee:01', by_bucket[1788080400])
        self.assertEqual('aa:bb:cc:dd:ee:02', by_bucket[1788084000])

    def test_the_join_cannot_multiply_the_octets_it_counts(self):
        self.store.store_buckets('p', [(1788080400, 'em0', '10.0.0.5', 'in', 100, 1)])
        for opened in (1788076800, 1788080400, 1788081000):
            self.observe('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', opened, 1788084000)
        self.store.commit()

        rows = list(self.store.traffic_rows(0))

        self.assertEqual(1, len(rows))
        self.assertEqual(100, rows[0]['octets'])


if __name__ == '__main__':
    unittest.main()
