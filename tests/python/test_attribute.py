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


WATCHING_SINCE = 1788000000


def row(interface, address, direction, octets, macs, mac, bucket=1788080000):
    return (bucket, interface, address, direction, octets, 1, macs, mac)


def classify(rows, interfaces=None, watching_since=WATCHING_SINCE):
    per_mac, unattributed, _ = attribute.classify(
        rows, interfaces or DEVICE_IFS, watching_since)
    return per_mac, unattributed


def worst(rows, interfaces=None, watching_since=WATCHING_SINCE):
    return attribute.classify(rows, interfaces or DEVICE_IFS, watching_since)[2]


class ClassifyTest(unittest.TestCase):
    def test_a_bucket_with_one_holder_goes_to_that_device(self):
        per_mac, _ = classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 1000, 1, 'aa:bb:cc:dd:ee:01'),
            row('vtnet1_vlan10', '10.10.10.5', 'out', 4000, 1, 'aa:bb:cc:dd:ee:01'),
        ])

        self.assertEqual(1000, per_mac['aa:bb:cc:dd:ee:01']['in']['octets'])
        self.assertEqual(4000, per_mac['aa:bb:cc:dd:ee:01']['out']['octets'])

    def test_the_far_end_of_a_flow_is_never_a_device(self):
        """91% of the operator's rows; the internet is not on his network"""
        per_mac, unattributed = classify([
            row('pppoe0', '142.250.185.78', 'out', 900, 0, None),
        ])

        self.assertEqual({}, per_mac)
        self.assertEqual(900, unattributed['far_end']['octets'])

    def test_an_address_nobody_was_seen_holding_is_named_not_dropped(self):
        _, unattributed = classify([
            row('vtnet1_vlan10', '10.10.10.77', 'in', 700, 0, None),
        ])

        self.assertEqual(700, unattributed['unknown']['octets'])

    def test_an_hour_two_devices_shared_is_refused_rather_than_guessed(self):
        per_mac, unattributed = classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 500, 2, 'aa:bb:cc:dd:ee:01'),
        ])

        self.assertEqual({}, per_mac)
        self.assertEqual(500, unattributed['ambiguous']['octets'])

    def test_traffic_from_before_lens_was_watching_is_not_called_a_gap(self):
        """129 GB of it on the operator's second firewall, and nothing to fix"""
        _, unattributed = classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 600, 0, None,
                bucket=WATCHING_SINCE - 7200),
        ])

        self.assertEqual(600, unattributed['not_watching']['octets'])
        self.assertEqual(0, unattributed['unknown']['octets'])

    def test_a_box_that_has_never_observed_blames_nothing_on_a_gap(self):
        _, unattributed = classify([
            row('vtnet1_vlan10', '10.10.10.5', 'in', 600, 0, None),
        ], watching_since=None)

        self.assertEqual(600, unattributed['not_watching']['octets'])

    def test_every_octet_lands_somewhere(self):
        rows = [
            row('vtnet1_vlan10', '10.10.10.5', 'in', 100, 1, 'aa:bb:cc:dd:ee:01'),
            row('pppoe0', '1.1.1.1', 'out', 200, 0, None),
            row('vtnet1_vlan20', '10.10.20.9', 'in', 300, 0, None),
            row('vtnet1_vlan20', '10.10.20.8', 'in', 400, 3, 'aa:bb:cc:dd:ee:02'),
        ]
        per_mac, unattributed = classify(rows)

        counted = sum(
            direction['octets'] for device in per_mac.values() for direction in device.values()
        ) + sum(reason['octets'] for reason in unattributed.values())

        self.assertEqual(1000, counted)


class BreakdownTest(unittest.TestCase):
    """
    A total nobody can break down is a number you either believe or ignore.
    Box 2 reported 95 GB unattributed against 43 GB attributed and the page
    could say so without saying what it was.
    """

    def test_the_heaviest_unattributed_addresses_come_back_biggest_first(self):
        rows = [
            row('vtnet1_vlan10', '10.10.10.77', 'in', 100, 0, None),
            row('vtnet1_vlan10', '10.10.10.99', 'in', 900, 0, None),
            row('pppoe0', '1.1.1.1', 'out', 500, 0, None),
        ]

        listed = worst(rows)

        self.assertEqual(['10.10.10.99', '1.1.1.1', '10.10.10.77'],
                         [entry['address'] for entry in listed])
        self.assertEqual('unknown', listed[0]['reason'])
        self.assertEqual('far_end', listed[1]['reason'])

    def test_the_same_address_over_many_hours_is_one_line_with_the_hours_counted(self):
        """what a person needs to see is one subnet, not four hundred rows of it"""
        rows = [
            row('vtnet1_vlan10', '10.10.10.77', 'in', 100, 0, None, bucket=WATCHING_SINCE + 3600),
            row('vtnet1_vlan10', '10.10.10.77', 'in', 100, 0, None, bucket=WATCHING_SINCE + 7200),
            row('vtnet1_vlan10', '10.10.10.77', 'in', 100, 0, None, bucket=WATCHING_SINCE + 10800),
        ]

        listed = worst(rows)

        self.assertEqual(1, len(listed))
        self.assertEqual(300, listed[0]['octets'])
        self.assertEqual(3, listed[0]['hours'])

    def test_the_same_address_on_two_interfaces_stays_two_lines(self):
        """which segment it was on is half the answer"""
        rows = [
            row('vtnet1_vlan10', '10.10.10.77', 'in', 100, 0, None),
            row('vtnet1_vlan20', '10.10.10.77', 'in', 100, 0, None),
        ]

        self.assertEqual(2, len(worst(rows)))

    def test_an_attributed_bucket_never_appears_in_the_breakdown(self):
        rows = [row('vtnet1_vlan10', '10.10.10.5', 'in', 100, 1, 'aa:bb:cc:dd:ee:01')]

        self.assertEqual([], worst(rows))

    def test_the_list_is_capped_so_the_far_end_cannot_flood_it(self):
        """tens of thousands of internet addresses answer nothing"""
        rows = [row('pppoe0', '203.0.113.%d' % n, 'out', n, 0, None) for n in range(1, 200)]

        listed = worst(rows)

        self.assertEqual(25, len(listed))
        self.assertEqual('203.0.113.199', listed[0]['address'])


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


class DetailAgreesWithTheListTest(unittest.TestCase):
    """
    A detail view whose total disagrees with the row that opened it is worse
    than no detail view: it makes both numbers unusable, and there is no way for
    the reader to tell which one lied. Both go through one query
    (`store.ATTRIBUTION_SQL`) and this test is why.
    """

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

    def build(self):
        """one device on two segments, one hour it shared, and some far end"""
        base = 1788080400
        self.store.store_buckets('p', [
            (base, 'em0', '10.0.0.5', 'in', 100, 1),
            (base, 'em0', '10.0.0.5', 'out', 400, 1),
            (base, 'em1', '10.0.9.5', 'in', 50, 1),
            (base + 3600, 'em0', '10.0.0.5', 'in', 700, 1),
            (base + 7200, 'em0', '10.0.0.9', 'in', 999, 1),
            (base, 'pppoe0', '1.1.1.1', 'out', 5000, 1),
        ])
        self.observe('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', base - 3600, base + 9000)
        self.observe('aa:bb:cc:dd:ee:01', '10.0.9.5', 'em1', base - 3600, base + 9000)
        # the shared hour: two devices on 10.0.0.9, so neither may claim it
        self.observe('aa:bb:cc:dd:ee:01', '10.0.0.9', 'em0', base + 7200, base + 9000)
        self.observe('aa:bb:cc:dd:ee:02', '10.0.0.9', 'em0', base + 7200, base + 9000)
        self.store.commit()
        return base

    def test_the_detail_total_equals_what_the_list_shows_for_that_device(self):
        self.build()
        interfaces = self.store.device_interfaces()

        per_mac, _, _ = attribute.classify(self.store.traffic_rows(0), interfaces, 0)
        listed = per_mac['aa:bb:cc:dd:ee:01']

        detail = {'in': 0, 'out': 0}
        for row in self.store.device_traffic('aa:bb:cc:dd:ee:01', 0):
            detail[row['direction']] += row['octets']

        self.assertEqual(listed['in']['octets'], detail['in'])
        self.assertEqual(listed['out']['octets'], detail['out'])
        self.assertEqual(1250, detail['in'] + detail['out'],
                         'the shared hour and the far end are both left out')

    def test_the_shared_hour_is_absent_from_the_detail_exactly_as_from_the_list(self):
        base = self.build()

        buckets = {row['bucket'] for row in
                   self.store.device_traffic('aa:bb:cc:dd:ee:01', 0)}

        self.assertNotIn(base + 7200, buckets)

    def test_the_far_end_never_reaches_a_devices_detail(self):
        self.build()

        addresses = self.store.device_traffic('aa:bb:cc:dd:ee:01', 0)

        self.assertNotIn(5000, [row['octets'] for row in addresses])

    def test_a_device_with_no_attributed_hours_gets_an_empty_series_not_an_error(self):
        self.build()

        self.assertEqual([], list(self.store.device_traffic('ff:ff:ff:ff:ff:ff', 0)))


class MomentTest(unittest.TestCase):
    """
    One slice of one device's chart, opened.

    The property that matters is that the parts add up to the bar. A drill-down
    whose rows sum to something other than the thing clicked is the §4.32
    failure one level further in, and it is the level where a reader is most
    likely to add the numbers up by hand.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        self.base = 1788080400
        self.store.store_buckets('p', [
            (self.base, 'em0', '10.0.0.5', 'in', 100, 1),
            (self.base, 'em0', '10.0.0.5', 'out', 400, 1),
            (self.base, 'em1', '10.0.9.5', 'in', 50, 1),
            (self.base + 3600, 'em0', '10.0.0.5', 'in', 900, 1),
            (self.base, 'pppoe0', '1.1.1.1', 'out', 7000, 1),
        ])
        for address, interface in (('10.0.0.5', 'em0'), ('10.0.9.5', 'em1')):
            self.store.db.execute(
                """INSERT INTO address_observation
                   (mac, address, interface, first_seen, last_seen)
                   VALUES ('aa:bb:cc:dd:ee:01', ?, ?, ?, ?)""",
                (address, interface, self.base - 3600, self.base + 9000),
            )
        self.store.commit()

    def tearDown(self):
        self.dir.cleanup()

    def test_the_parts_add_up_to_the_bar_that_was_clicked(self):
        total = 0
        for row in self.store.device_traffic('aa:bb:cc:dd:ee:01', 0):
            if row['bucket'] == self.base:
                total += row['octets']

        parts = sum(r['octets'] for r in
                    self.store.device_moment('aa:bb:cc:dd:ee:01', self.base, 3600))

        self.assertEqual(550, total)
        self.assertEqual(total, parts)

    def test_a_device_on_two_segments_shows_both_in_one_slice(self):
        rows = list(self.store.device_moment('aa:bb:cc:dd:ee:01', self.base, 3600))

        self.assertEqual({('10.0.0.5', 'em0'), ('10.0.9.5', 'em1')},
                         {(r['address'], r['interface']) for r in rows})

    def test_a_daily_slice_covers_every_hour_inside_it(self):
        day = self.base - (self.base % 86400)

        parts = sum(r['octets'] for r in
                    self.store.device_moment('aa:bb:cc:dd:ee:01', day, 86400))

        self.assertEqual(1450, parts, 'both hours, and still not the far end')

    def test_the_far_end_never_appears_in_a_devices_slice(self):
        addresses = {r['address'] for r in
                     self.store.device_moment('aa:bb:cc:dd:ee:01', self.base, 3600)}

        self.assertNotIn('1.1.1.1', addresses)

    def test_an_empty_slice_is_empty_and_not_an_error(self):
        self.assertEqual([], list(
            self.store.device_moment('aa:bb:cc:dd:ee:01', self.base + 86400 * 5, 3600)))


class InterfaceTrafficTest(unittest.TestCase):
    """per-segment totals, on the same attribution query as everything else"""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        base = 1788080400
        self.store.store_buckets('p', [
            (base, 'em0', '10.0.0.5', 'in', 100, 1),
            (base, 'em0', '10.0.0.9', 'in', 300, 1),
            (base, 'pppoe0', '1.1.1.1', 'out', 900, 1),
        ])
        self.store.db.execute(
            """INSERT INTO address_observation
               (mac, address, interface, first_seen, last_seen)
               VALUES ('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', ?, ?)""",
            (base, base + 7200),
        )
        self.store.commit()

    def tearDown(self):
        self.dir.cleanup()

    def rows(self):
        return {(r['interface'], r['direction']): r
                for r in self.store.interface_traffic(0)}

    def test_a_segment_reports_what_it_carried_and_what_had_a_device(self):
        row = self.rows()[('em0', 'in')]

        self.assertEqual(400, row['octets'])
        self.assertEqual(100, row['named'], 'only 10.0.0.5 was ever observed')

    def test_the_far_side_carries_traffic_and_names_none_of_it(self):
        row = self.rows()[('pppoe0', 'out')]

        self.assertEqual(900, row['octets'])
        self.assertEqual(0, row['named'])

    def test_the_totals_match_what_the_device_list_attributes(self):
        """the segment page and the device page are the same bytes (§4.32)"""
        per_mac, _, _ = attribute.classify(
            self.store.traffic_rows(0), self.store.device_interfaces(), 0)

        named = sum(row['named'] for row in self.store.interface_traffic(0))
        listed = sum(d['octets'] for device in per_mac.values() for d in device.values())

        self.assertEqual(listed, named)


if __name__ == '__main__':
    unittest.main()
