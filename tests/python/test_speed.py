"""
Stage 41 (§4.69): day-long window pieces, a join that seeks, complete days
summed once -- and none of it may change a single attributed byte.
"""

import os
import random
import sqlite3
import sys
import tempfile
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

from lenslib import store as storelib                          # noqa: E402
from lenslib.store import MIGRATIONS, Store, WINDOW_MAX        # noqa: E402

DAY = 86400
HOUR = 3600
T0 = 1789992000 - 1789992000 % DAY

# the join as it was before stage 41: no lower bound, no upper bound
UNBOUNDED = """
    SELECT mac, bucket / 86400 AS day, sum(octets) AS octets
    FROM (
        SELECT t.bucket AS bucket, t.octets AS octets,
               count(DISTINCT o.mac) AS macs, min(o.mac) AS mac
        FROM traffic_hour t
        LEFT JOIN address_observation o
          ON o.address = t.address AND o.interface = t.interface
         AND o.first_seen < t.bucket + 3600 AND o.last_seen >= t.bucket
        WHERE t.bucket >= ?
        GROUP BY t.bucket, t.interface, t.address, t.direction
    ) WHERE macs = 1 GROUP BY mac, day ORDER BY mac, day
"""


class Base(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')

    def tearDown(self):
        self.dir.cleanup()


class MigrationTest(Base):
    def test_a_long_window_is_cut_into_days_and_nothing_is_lost(self):
        db = sqlite3.connect(self.path)
        db.execute("CREATE TABLE schema_version (version INTEGER NOT NULL)")
        for version in range(1, 10):
            for statement in MIGRATIONS[version]:
                db.execute(statement)
            db.execute("INSERT INTO schema_version(version) VALUES (?)", (version,))
        db.execute("INSERT INTO address_observation VALUES ('aa', '10.0.0.5', 'em0', ?, ?)",
                   (T0, T0 + 3 * DAY + 5 * HOUR))
        db.execute("INSERT INTO address_observation VALUES ('bb', '10.0.0.6', 'em0', ?, ?)", (T0, T0 + HOUR))
        db.commit()
        db.close()

        store = Store(self.path)
        pieces = [tuple(row) for row in store.db.execute(
            "SELECT first_seen, last_seen FROM address_observation WHERE mac = 'aa' ORDER BY first_seen")]

        self.assertEqual(4, len(pieces))
        self.assertTrue(all(end - start <= WINDOW_MAX for start, end in pieces))
        self.assertEqual(T0, pieces[0][0])
        self.assertEqual(T0 + 3 * DAY + 5 * HOUR, pieces[-1][1])
        for (_, end), (start, _) in zip(pieces, pieces[1:]):
            self.assertEqual(end, start, 'each piece begins where the last one ended')
        self.assertEqual([{'mac': 'aa', 'address': '10.0.0.5', 'interface': 'em0',
                           'first_seen': T0, 'last_seen': T0 + 3 * DAY + 5 * HOUR}],
                         store.device_windows('aa'), 'readers see one stay')


class ObserveTest(Base):
    def test_a_window_that_has_run_a_day_goes_on_in_a_new_piece(self):
        store = Store(self.path)
        key = ('aa', '10.0.0.5', 'em0')
        store.open_new_windows([key], T0)
        store.extend_windows([key], T0 + DAY - 300)
        store.extend_windows([key], T0 + DAY + 300)
        store.extend_windows([key], T0 + DAY + 600)

        pieces = [tuple(row) for row in store.db.execute(
            "SELECT first_seen, last_seen FROM address_observation ORDER BY first_seen")]
        self.assertEqual([(T0, T0 + DAY - 300), (T0 + DAY - 300, T0 + DAY + 600)], pieces)
        self.assertEqual(1, len(store.device_windows('aa')), 'still one visit')


class SameAnswerTest(Base):
    """The whole point: faster, and not one byte attributed differently."""

    def setUp(self):
        super().setUp()
        self.store = Store(self.path)
        rng = random.Random(3)
        for n in range(12):
            mac, address = 'aa:%02x' % n, '10.0.0.%d' % (n % 7)     # addresses shared over time
            t = T0 - 20 * DAY
            while t < T0:
                stay = rng.choice([HOUR, 5 * HOUR, 3 * DAY])
                if rng.random() < 0.5:
                    self.store.record_window(mac, address, 'em0', t, min(T0, t + stay))
                t += stay + rng.choice([HOUR, 7 * HOUR, 2 * DAY])
        rows = []
        for hour in range(T0 - 20 * DAY, T0, HOUR):
            for n in range(7):
                rows.append((hour, 'em0', '10.0.0.%d' % n, 'in', rng.randrange(1, 1000), 1))
        self.store.store_buckets('FlowSourceAddrTotals', rows)
        self.store.commit()

    def unbounded(self, since):
        return [(r[0], r[1], r[2]) for r in self.store.db.execute(UNBOUNDED, (since,))]

    def mine(self, since):
        return [(r['mac'], r['day'], r['octets']) for r in self.store.daily_totals(since)]

    def test_the_bounded_join_attributes_exactly_what_the_old_one_did(self):
        self.assertEqual(self.unbounded(T0 - 20 * DAY), self.mine(T0 - 20 * DAY))

    def test_summed_days_answer_exactly_what_the_join_answers(self):
        live = self.mine(T0 - 20 * DAY)

        summed = self.store.fill_device_days(T0 + 12 * HOUR, limit=31)

        self.assertEqual(20, summed)
        self.assertEqual(live, self.mine(T0 - 20 * DAY))

    def test_a_late_bucket_drops_its_day_until_it_is_summed_again(self):
        self.store.fill_device_days(T0 + 12 * HOUR, limit=31)
        day = (T0 - 3 * DAY) // DAY

        self.store.store_buckets('FlowSourceAddrTotals', [(T0 - 3 * DAY + 5 * HOUR, 'em1', '10.9.9.9', 'in', 5, 1)])

        done = {row[0] for row in self.store.db.execute("SELECT day FROM device_day_done")}
        self.assertNotIn(day, done)
        self.assertEqual(self.unbounded(T0 - 20 * DAY), self.mine(T0 - 20 * DAY))

    def test_today_is_never_summed(self):
        self.store.fill_device_days(T0 + 12 * HOUR)

        done = {row[0] for row in self.store.db.execute("SELECT day FROM device_day_done")}
        self.assertNotIn(T0 // DAY, done)

    def test_purge_takes_the_sums(self):
        self.store.fill_device_days(T0 + 12 * HOUR)
        self.store.purge()

        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM device_day").fetchone()[0])


class StaysTest(unittest.TestCase):
    def test_pieces_join_and_real_gaps_stay(self):
        rows = [
            {'mac': 'a', 'address': 'x', 'interface': 'i', 'first_seen': 0, 'last_seen': 10},
            {'mac': 'a', 'address': 'x', 'interface': 'i', 'first_seen': 10, 'last_seen': 20},
            {'mac': 'a', 'address': 'x', 'interface': 'i', 'first_seen': 30, 'last_seen': 40},
            {'mac': 'b', 'address': 'x', 'interface': 'i', 'first_seen': 40, 'last_seen': 50},
        ]

        self.assertEqual([(0, 20), (30, 40), (40, 50)],
                         [(s['first_seen'], s['last_seen']) for s in storelib.stays(rows)])


if __name__ == '__main__':
    unittest.main()
