"""
Forgetting one device (§4.72): exactly its rows and the hours its windows
cover go, a neighbour's stay, and the dry run counts what the real one deletes.
"""

import io
import json
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from unittest import mock

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

import collect                                                  # noqa: E402
from lenslib.store import Store                                 # noqa: E402

DAY = 86400
NOW = 1790000000 - 1790000000 % DAY + 12 * 3600
GONE = 'aa:00:00:00:00:01'
ROTATED = 'aa:00:00:00:00:02'
STAYS = 'bb:00:00:00:00:01'


class ForgetTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.store = Store(self.path)
        db = self.store.db
        for mac in (GONE, ROTATED, STAYS):
            self.store.see_device(mac, NOW - 2 * DAY, False, False, None, None)
        # the device held .5 for two hours, then its rotated MAC held .6 for one;
        # the neighbour held .7 all along, and .5 after the device left
        windows = [
            (GONE, '10.0.0.5', NOW - 6 * 3600, NOW - 4 * 3600 - 1),
            (ROTATED, '10.0.0.6', NOW - 3 * 3600, NOW - 2 * 3600 - 1),
            (STAYS, '10.0.0.7', NOW - 6 * 3600, NOW),
            (STAYS, '10.0.0.5', NOW - 2 * 3600, NOW),
        ]
        for mac, address, first, last in windows:
            db.execute("INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
                       " VALUES (?, ?, 'em1', ?, ?)", (mac, address, first, last))
        for hour in range(6):
            bucket = NOW - (6 - hour) * 3600
            for address in ('10.0.0.5', '10.0.0.6', '10.0.0.7'):
                db.execute("INSERT INTO traffic_hour (bucket, interface, address, direction, octets, packets)"
                           " VALUES (?, 'em1', ?, 'in', 100, 1)", (bucket, address))
        day = NOW - NOW % DAY
        for address in ('10.0.0.5', '10.0.0.7'):
            db.execute("INSERT INTO destination_day (day, interface, address, peer, port, protocol, direction,"
                       " octets, packets) VALUES (?, 'em1', ?, '9.9.9.9', 443, 6, 'in', 10, 1)", (day, address))
        db.execute("INSERT INTO device_day (mac, day, octets, sent) VALUES (?, ?, 1, 1)", (GONE, day // DAY - 1))
        db.execute("INSERT INTO device_day (mac, day, octets, sent) VALUES (?, ?, 1, 1)", (STAYS, day // DAY - 1))
        self.store.set_label(GONE, {'name': 'Phone', 'kind': '', 'tags': '', 'note': '', 'owner': 'Anna'}, NOW)
        self.store.commit()

    def tearDown(self):
        self.dir.cleanup()

    def count(self, sql, *args):
        return self.store.db.execute(sql, args).fetchone()[0]

    def test_the_dry_run_counts_what_the_real_one_deletes_and_deletes_nothing(self):
        dry = self.store.forget([GONE, ROTATED], dry=True)
        self.assertEqual(18, self.count("SELECT count(*) FROM traffic_hour"))

        wet = self.store.forget([GONE, ROTATED])

        self.assertEqual(dry, wet)
        self.assertEqual({'traffic_hours': 3, 'destination_days': 1, 'windows': 2, 'summed_days': 1,
                          'labels': 1, 'devices': 2, 'pauses': 0, 'paused': 0}, wet)

    def test_only_the_hours_its_windows_cover_go(self):
        self.store.forget([GONE, ROTATED])

        left = {(row[0], row[1]) for row in self.store.db.execute("SELECT address, bucket FROM traffic_hour")}
        for hour in (6, 5):
            self.assertNotIn(('10.0.0.5', NOW - hour * 3600), left, 'the device\'s own hours')
        self.assertNotIn(('10.0.0.6', NOW - 3 * 3600), left, 'the rotated MAC is the same device')
        self.assertIn(('10.0.0.5', NOW - 2 * 3600), left, 'the neighbour\'s hour on the same address stays')
        self.assertEqual(6, sum(1 for address, _ in left if address == '10.0.0.7'))

    def test_the_neighbour_keeps_everything_of_its_own(self):
        self.store.forget([GONE, ROTATED])

        self.assertEqual([STAYS], [row[0] for row in self.store.db.execute("SELECT mac FROM device")])
        self.assertEqual(2, self.count("SELECT count(*) FROM address_observation WHERE mac = ?", STAYS))
        self.assertEqual(1, self.count("SELECT count(*) FROM device_day WHERE mac = ?", STAYS))
        self.assertEqual(0, self.count("SELECT count(*) FROM device_label"))
        self.assertEqual(['10.0.0.7'], [row[0] for row in self.store.db.execute(
            "SELECT address FROM destination_day")])

    def test_an_unknown_device_deletes_nothing(self):
        before = self.count("SELECT count(*) FROM traffic_hour")

        counts = self.store.forget(['cc:00:00:00:00:01'])

        self.assertEqual(0, counts['devices'])
        self.assertEqual(before, self.count("SELECT count(*) FROM traffic_hour"))

    def test_the_duty_answers_with_the_counts(self):
        out = io.StringIO()
        with mock.patch.object(collect, 'DB_PATH', self.path), redirect_stdout(out):
            self.assertEqual(0, collect.forget(GONE + ',' + ROTATED, True))
        answer = json.loads(out.getvalue())

        self.assertTrue(answer['dry'])
        self.assertEqual([GONE, ROTATED], answer['macs'])
        self.assertEqual(2, answer['counts']['devices'])

    def test_what_is_kept_counts_every_kind(self):
        kept = self.store.kept()

        self.assertEqual(3, kept['devices']['rows'])
        self.assertEqual(18, kept['traffic_hours']['rows'])
        self.assertEqual(1, kept['owners']['rows'])
        self.assertEqual(NOW - 6 * 3600, kept['windows']['oldest'])


if __name__ == '__main__':
    unittest.main()
