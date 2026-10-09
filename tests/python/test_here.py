"""
Who is on each network now (stage 51): the devices the last observation saw,
counted per interface, once per MAC however many addresses it holds.
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

from lenslib.store import Store                                 # noqa: E402


class HereTest(unittest.TestCase):

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))

    def tearDown(self):
        self.store.db.close()
        self.dir.cleanup()

    def test_one_count_per_mac_and_only_since_the_last_look(self):
        rows = [
            ('aa:00:00:00:00:01', '10.0.20.5', 'vlan20', 1000, 5000),
            ('aa:00:00:00:00:01', '2a02::5', 'vlan20', 1000, 5000),    # same device, second address
            ('aa:00:00:00:00:02', '10.0.20.6', 'vlan20', 1000, 5000),
            ('aa:00:00:00:00:03', '10.0.21.7', 'vlan21', 1000, 2000),  # left before the last look
        ]
        self.store.db.executemany(
            'INSERT INTO address_observation (mac, address, interface, first_seen, last_seen) VALUES (?,?,?,?,?)',
            rows)
        self.assertEqual({'vlan20': 2}, self.store.here_by_interface(4900))
        self.assertEqual({'vlan20': 2, 'vlan21': 1}, self.store.here_by_interface(0))


if __name__ == '__main__':
    unittest.main()
