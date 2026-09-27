"""
Upload judged on its own, and last week beside this one (§4.66).
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
from lenslib import baseline                                    # noqa: E402
from lenslib.store import Store                                 # noqa: E402

MB = 1024 * 1024
TODAY = 20000
HOUR = 3600


def days(mac, total, sent, today_total, today_sent):
    rows = [(mac, TODAY - n, total, sent) for n in range(1, 26)]
    return rows + [(mac, TODAY, today_total, today_sent)]


class UploadTest(unittest.TestCase):
    def test_a_camera_streaming_out_is_caught_though_its_total_is_ordinary_ish(self):
        # its day is 4 GB anyway; today it sent 3 GB more than its usual 400 MB
        report = baseline.assess_both(days('cam', 4000 * MB, 400 * MB, 5000 * MB, 3400 * MB), TODAY)

        self.assertEqual(1, len(report['unusual']))
        entry = report['unusual'][0]
        self.assertEqual('sent', entry['direction'])
        self.assertEqual(3400 * MB, entry['sent'])
        self.assertEqual(8.5, entry['sent_times'])

    def test_a_download_is_unusual_with_its_uploads_ordinary(self):
        report = baseline.assess_both(days('laptop', 500 * MB, 50 * MB, 5000 * MB, 60 * MB), TODAY)

        self.assertEqual('total', report['unusual'][0]['direction'])
        self.assertNotIn('sent', report['unusual'][0])

    def test_both_at_once_is_told_as_the_upload(self):
        report = baseline.assess_both(days('nas', 500 * MB, 200 * MB, 9000 * MB, 8500 * MB), TODAY)

        self.assertEqual(['sent'], [entry['direction'] for entry in report['unusual']])

    def test_the_upload_has_the_same_floor(self):
        # 10 KB against 1 KB is ten times, and nothing
        report = baseline.assess_both(days('plug', MB, 1024, MB, 10 * 1024), TODAY)

        self.assertEqual([], report['unusual'])


class PreviousWeekTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        self.now = TODAY * 86400 + 12 * HOUR

    def tearDown(self):
        self.dir.cleanup()

    def seed(self, since):
        mac = 'aa:00:00:00:00:01'
        self.store.see_device(mac, since, False, False, None, None)
        self.store.db.execute(
            "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
            " VALUES (?, '10.0.0.5', 'em1', ?, ?)", (mac, since, self.now))
        self.store.store_buckets('FlowSourceAddrTotals', [
            (self.now - 7 * 86400 - 2 * HOUR, 'em1', '10.0.0.5', 'in', 300, 1),
            (self.now - 7 * 86400 - 2 * HOUR, 'em1', '10.0.0.5', 'out', 700, 1),
            (self.now - 2 * HOUR, 'em1', '10.0.0.5', 'in', 50, 1),
            (since, 'em1', '10.0.0.5', 'in', 1, 1),
        ])
        self.store.commit()
        return mac

    def test_the_same_day_last_week_is_compared_when_it_was_watched(self):
        mac = self.seed(self.now - 10 * 86400)

        previous = collect.traffic(self.store, self.now, 24)['previous']

        self.assertTrue(previous['covered'])
        self.assertEqual(self.now - 8 * 86400, previous['since'])
        self.assertEqual(self.now - 7 * 86400, previous['until'])
        self.assertEqual({'octets': 1000, 'sent': 300}, previous['devices'][mac])

    def test_a_week_lens_did_not_watch_all_of_is_withheld(self):
        self.seed(self.now - 7 * 86400 - 5 * HOUR)

        previous = collect.traffic(self.store, self.now, 24)['previous']

        self.assertFalse(previous['covered'])
        self.assertNotIn('devices', previous)


if __name__ == '__main__':
    unittest.main()
