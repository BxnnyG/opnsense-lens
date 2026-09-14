"""
The first judgement Lens makes.

Every test here is about restraint. A verdict that cries wolf in week one would
spend the credibility every other number on these pages has been earning since
stage 4, and the page has promised three weeks of learning since stage 4 too.
"""

import os
import sys
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

from lenslib import baseline                                    # noqa: E402

MB = 1024 * 1024
TODAY = 20000


def history(mac, days, octets, today=None):
    rows = [(mac, TODAY - n, octets) for n in range(1, days + 1)]
    if today is not None:
        rows.append((mac, TODAY, today))
    return rows


class BaselineTest(unittest.TestCase):
    def test_a_device_without_three_weeks_is_never_called_unusual(self):
        report = baseline.assess(history('aa', 10, 10 * MB, today=9999 * MB), TODAY)

        self.assertEqual([], report['unusual'])
        self.assertEqual(1, report['learning'])
        self.assertEqual(10, report['days'])

    def test_a_quiet_device_having_a_loud_day_is_named(self):
        report = baseline.assess(history('aa', 25, 100 * MB, today=2000 * MB), TODAY)

        self.assertEqual(1, len(report['unusual']))
        self.assertEqual(20.0, report['unusual'][0]['times'])
        self.assertEqual(100 * MB, report['unusual'][0]['usual'])

    def test_a_busy_device_staying_busy_is_not_unusual(self):
        report = baseline.assess(history('aa', 25, 5000 * MB, today=6000 * MB), TODAY)

        self.assertEqual([], report['unusual'])

    def test_three_kilobytes_against_one_is_not_an_event(self):
        """
        Three times normal, true, and the fastest way to teach someone to
        ignore the column. The floor exists for exactly this row.
        """
        report = baseline.assess(history('aa', 25, 1024, today=3072), TODAY)

        self.assertEqual([], report['unusual'])

    def test_one_backup_night_does_not_become_the_new_normal(self):
        """a median, not a mean: one huge day must not raise the bar for weeks"""
        rows = history('aa', 25, 100 * MB)
        rows.append(('aa', TODAY - 3, 50000 * MB))
        rows.append(('aa', TODAY, 2000 * MB))

        report = baseline.assess(rows, TODAY)

        self.assertEqual(1, len(report['unusual']), 'the median ignores the outlier')

    def test_today_is_never_part_of_what_is_usual(self):
        """the day is still being written; including it would flatten every spike"""
        rows = history('aa', 21, 100 * MB, today=900 * MB)

        report = baseline.assess(rows, TODAY)

        self.assertEqual(100 * MB, report['unusual'][0]['usual'])

    def test_a_device_seen_for_the_first_time_today_is_left_to_the_summary(self):
        report = baseline.assess([('aa', TODAY, 9999 * MB)], TODAY)

        self.assertEqual([], report['unusual'])

    def test_the_loudest_is_first(self):
        rows = history('aa', 25, 100 * MB, today=900 * MB)
        rows += history('bb', 25, 100 * MB, today=4000 * MB)

        report = baseline.assess(rows, TODAY)

        self.assertEqual(['bb', 'aa'], [entry['mac'] for entry in report['unusual']])

    def test_an_empty_store_answers_rather_than_failing(self):
        report = baseline.assess([], TODAY)

        self.assertEqual([], report['unusual'])
        self.assertEqual(0, report['days'])


if __name__ == '__main__':
    unittest.main()
