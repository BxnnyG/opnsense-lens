"""
Each settled hour per interface (stage 58): the Networks page's sums from
interface_hour equal the live attribution join -- with nothing settled, part
of it, and all of it.
"""

import os
import shutil
import sys
import tempfile
import time
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tools', 'preview'))

from lenslib.store import Store                                 # noqa: E402


def shape(rows):
    return sorted((r['interface'], r['direction'], r['octets'], r['packets'], r['named'], r['hours'], r['addresses'])
                  for r in rows)


class InterfaceHoursTest(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        import seed
        cls.dir = tempfile.mkdtemp()
        cls.now = int(time.time())
        cls.path = os.path.join(cls.dir, 'seeded.sqlite')
        seed.seed(cls.path, 4, cls.now)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.dir)

    def test_settled_sums_equal_the_live_join(self):
        store = Store(self.path)
        store.db.execute('DELETE FROM hour_done')
        store.db.execute('DELETE FROM interface_hour')
        since = self.now - 3 * 86400
        live = shape(store.interface_traffic(since))
        self.assertTrue(live, 'the seeded store has traffic')

        store.fill_hours(self.now, limit=40)            # some of it
        self.assertEqual(live, shape(store.interface_traffic(since)))

        store.fill_hours(self.now, limit=1000)          # all of it
        self.assertEqual(live, shape(store.interface_traffic(since)))
        store.commit()
        store.db.close()

    def test_forgetting_an_hour_unsettles_its_interface_sums_too(self):
        store = Store(self.path)
        store.fill_hours(self.now, limit=1000)
        bucket = store.db.execute('SELECT max(bucket) FROM interface_hour').fetchone()[0]
        store._forget_hour(bucket)
        self.assertEqual(0, store.db.execute('SELECT count(*) FROM interface_hour WHERE bucket = ?',
                                             (bucket,)).fetchone()[0])
        store.db.rollback()
        store.db.close()


if __name__ == '__main__':
    unittest.main()
