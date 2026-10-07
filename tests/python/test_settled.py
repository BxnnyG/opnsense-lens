"""
Settled hours (§4.76): the device list adds up sums the harvest made instead of
joining a week of raw buckets on every page load. The only property that
matters is that the answer does not change -- a fast page with different
numbers would be the §4.32 failure with a stopwatch attached.
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
from lenslib import attribute                                   # noqa: E402
from lenslib.store import Store                                 # noqa: E402

BASE = 1788080400
HOUR = 3600


class SettledTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        buckets = []
        for h in range(30):
            at = BASE + h * HOUR
            buckets += [
                (at, 'em0', '10.0.0.5', 'in', 100 + h, 2),
                (at, 'em0', '10.0.0.5', 'out', 400 + h, 3),
                (at, 'em0', '10.0.0.9', 'in', 50, 1),          # shared below: ambiguous
                (at, 'em0', '10.0.0.77', 'in', 7, 1),          # nobody: unknown
                (at, 'pppoe0', '1.1.1.1', 'out', 9000, 1),     # far end
            ]
        self.store.store_buckets('FlowSourceAddrTotals', buckets)
        for mac, address, first, last in (
                ('aa', '10.0.0.5', BASE - HOUR, BASE + 40 * HOUR),
                ('aa', '10.0.0.9', BASE - HOUR, BASE + 40 * HOUR),
                ('bb', '10.0.0.9', BASE - HOUR, BASE + 40 * HOUR)):
            self.store.db.execute(
                """INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)
                   VALUES (?, ?, 'em0', ?, ?)""", (mac, address, first, last))
        self.store.see_device('aa', BASE, randomised=False, is_local=False)
        self.store.see_device('bb', BASE, randomised=False, is_local=False)
        self.store.commit()
        self.now = BASE + 30 * HOUR + 1800

    def tearDown(self):
        self.dir.cleanup()

    def live(self, since, until):
        status = self.store.status()
        per_mac, unattributed, _ = attribute.classify(
            self.store.traffic_rows(since, until=until), self.store.device_interfaces(),
            status['first_observation'])
        return per_mac, unattributed

    def answer(self, since, until):
        status = self.store.status()
        return collect.attributed(self.store, since, until, self.store.device_interfaces(), status)

    def assertSameTotals(self, left, right):
        for mac in set(left[0]) | set(right[0]):
            for side in ('in', 'out'):
                self.assertEqual(left[0][mac][side]['octets'], right[0][mac][side]['octets'], (mac, side))
        for reason in left[1]:
            self.assertEqual(left[1][reason]['octets'], right[1][reason]['octets'], reason)

    def test_settling_changes_nothing_but_the_time_it_takes(self):
        since = BASE + 3 * HOUR + 600          # a ragged left edge, on purpose
        before = self.answer(since, self.now)

        settled = self.store.fill_hours(self.now)
        self.store.commit()
        after = self.answer(since, self.now)

        self.assertGreater(settled, 20)
        self.assertSameTotals(before, after)
        self.assertSameTotals(self.live(since, self.now), after)

    def test_the_hours_that_may_still_change_are_never_settled(self):
        self.store.fill_hours(self.now)
        self.store.commit()

        newest = self.store.db.execute("SELECT max(bucket) FROM hour_done").fetchone()[0]

        self.assertLessEqual(newest + HOUR, self.store.settled_before(self.now))

    def test_a_bounded_run_settles_newest_first(self):
        self.store.fill_hours(self.now, limit=3)
        done = sorted(row[0] for row in self.store.db.execute("SELECT bucket FROM hour_done"))

        self.assertEqual(3, len(done))
        self.assertEqual(done, sorted(done)[-3:])
        self.assertSameTotals(self.live(BASE, self.now), self.answer(BASE, self.now))

    def test_forgetting_a_device_unsettles_the_hours_it_was_in(self):
        self.store.fill_hours(self.now)
        self.store.commit()

        self.store.forget(['aa'])
        after = self.answer(BASE, self.now)

        self.assertNotIn('aa', after[0])
        self.assertSameTotals(self.live(BASE, self.now), after)

    def test_purge_and_prune_take_the_sums_with_them(self):
        self.store.fill_hours(self.now)
        self.store.commit()
        self.store.prune(10 ** 10)
        self.store.commit()

        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM device_hour").fetchone()[0])
        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM hour_done").fetchone()[0])


if __name__ == '__main__':
    unittest.main()
