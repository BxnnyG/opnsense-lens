"""
Daily totals with gaps (stage 54): a day not summed yet is summed live over
its own days only, and a day before the first bucket is not asked for -- the
answer equals the live join over the whole range either way.
"""

import os
import shutil
import sys
import tempfile
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tools', 'preview'))

from lenslib import store as storelib                           # noqa: E402
from lenslib.store import Store, _runs                          # noqa: E402


class RunsTest(unittest.TestCase):

    def test_consecutive_days_are_one_run(self):
        self.assertEqual([(1, 3), (5, 5), (7, 8)], _runs([3, 1, 2, 5, 8, 7]))
        self.assertEqual([], _runs([]))


class DayGapsTest(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        import seed
        cls.dir = tempfile.mkdtemp()
        cls.path = os.path.join(cls.dir, 'seeded.sqlite')
        import time
        seed.seed(cls.path, 30, int(time.time()))

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.dir)

    def test_gaps_and_days_before_the_first_bucket_give_the_live_answer(self):
        work = os.path.join(self.dir, 'work.sqlite')
        shutil.copy(self.path, work)
        store = Store(work)
        first = store.db.execute('SELECT min(bucket) FROM traffic_hour').fetchone()[0] // 86400
        import time
        store.fill_device_days(int(time.time()), limit=60)
        # holes: two separate days the harvest did not sum
        store.db.execute('DELETE FROM device_day_done WHERE day IN (?, ?)', (first + 3, first + 9))
        store.db.execute('DELETE FROM device_day WHERE day IN (?, ?)', (first + 3, first + 9))
        since = (first - 20) * 86400        # reaches back before the first bucket

        expected = sorted((r['mac'], r['day'], r['octets'], r['sent'])
                          for r in store._day_sums(since, storelib.FOREVER))
        answered = sorted((r['mac'], r['day'], r['octets'], r['sent']) for r in store.daily_totals(since))
        self.assertEqual(expected, answered)


if __name__ == '__main__':
    unittest.main()
