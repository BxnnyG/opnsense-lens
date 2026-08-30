"""
The store: migrations, windows, buckets, retention and the purge promise.

Run:  python3 -m unittest discover -s tests/python
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

from lenslib.store import Store, SCHEMA_VERSION, MIGRATIONS     # noqa: E402


class StoreTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.store = Store(self.path)

    def tearDown(self):
        self.dir.cleanup()

    def test_a_fresh_store_is_migrated_and_not_world_readable(self):
        """every row here describes what a person did on the network (S14)"""
        self.assertEqual(SCHEMA_VERSION, self.store.status()['schema_version'])
        self.assertEqual(0o600, os.stat(self.path).st_mode & 0o777)

    def test_migrating_twice_is_harmless(self):
        again = Store(self.path)

        self.assertEqual(SCHEMA_VERSION, again.status()['schema_version'])

    def test_defaults_exist_so_retention_is_never_unset(self):
        self.assertEqual(365, self.store.setting_int('retention_days'))
        self.assertEqual(500, self.store.setting_int('disk_ceiling_mb'))

    def test_a_window_is_extended_in_place_and_a_gap_opens_a_new_one(self):
        key = ('42:c5:38:e1:54:c7', '10.10.10.141', 'vtnet1_vlan10')

        self.store.open_new_windows([key], 1000)
        self.store.extend_windows([key], 1200)
        self.assertEqual({key: 1200}, self.store.open_windows())

        self.store.open_new_windows([key], 9000)
        self.store.commit()

        rows = self.store.db.execute(
            "SELECT first_seen, last_seen FROM address_observation ORDER BY first_seen"
        ).fetchall()
        self.assertEqual([(1000, 1200), (9000, 9000)], [tuple(r) for r in rows])

    def test_a_device_keeps_the_hostname_it_had_when_a_later_sighting_has_none(self):
        """DHCP going quiet must not erase what it already told us"""
        self.store.see_device('aa:bb:cc:dd:ee:ff', 1000, False, False, 'BXY-Pixel-10', 'dnsmasq')
        self.store.see_device('aa:bb:cc:dd:ee:ff', 2000, False, False, None, None)
        self.store.commit()

        row = self.store.db.execute("SELECT * FROM device").fetchone()
        self.assertEqual('BXY-Pixel-10', row['hostname'])
        self.assertEqual(1000, row['first_seen'])
        self.assertEqual(2000, row['last_seen'])

    def test_devices_carry_every_address_they_hold_not_only_the_latest(self):
        """the operator's admin PC is in two VLANs at once -- one device, two rows"""
        self.store.see_device('42:c5:38:e1:54:c7', 1000, randomised=False, is_local=False,
                              hostname='admin-pc', source='dnsmasq')
        self.store.open_new_windows([
            ('42:c5:38:e1:54:c7', '10.0.10.5', 'HOME'),
            ('42:c5:38:e1:54:c7', '10.0.99.5', 'MGNT'),
        ], 1000)

        devices = self.store.devices()

        self.assertEqual(1, len(devices))
        self.assertEqual('admin-pc', devices[0]['hostname'])
        self.assertEqual(
            {('10.0.10.5', 'HOME'), ('10.0.99.5', 'MGNT')},
            {(a['address'], a['interface']) for a in devices[0]['addresses']},
        )

    def test_a_device_with_no_observation_yet_is_still_listed(self):
        self.store.see_device('aa:bb:cc:dd:ee:ff', 1000, randomised=True, is_local=False)

        devices = self.store.devices()

        self.assertEqual(1, len(devices))
        self.assertEqual([], devices[0]['addresses'])
        self.assertTrue(devices[0]['randomised'])

    def test_devices_on_an_empty_store_is_a_list_not_an_error(self):
        self.assertEqual([], self.store.devices())

    def test_buckets_are_stored_once_and_the_watermark_only_moves_forward(self):
        rows = [(1787990400, 'vtnet1_vlan20', '10.10.20.115', 'in', 120, 3),
                (1787994000, 'vtnet1_vlan20', '10.10.20.115', 'in', 300, 5)]

        self.assertEqual(2, self.store.store_buckets('FlowSourceAddrTotals', rows))
        self.assertEqual(0, self.store.store_buckets('FlowSourceAddrTotals', rows))
        self.assertEqual(1787994000, self.store.last_bucket('FlowSourceAddrTotals'))

        self.store.store_buckets('FlowSourceAddrTotals', [(1787900000, 'em0', '10.0.0.1', 'in', 1, 1)])
        self.assertEqual(1787994000, self.store.last_bucket('FlowSourceAddrTotals'))

    def test_retention_removes_what_is_past_the_cutoff_and_nothing_else(self):
        now = 1788000000
        old = now - 400 * 86400

        self.store.store_buckets('p', [(old, 'em0', '10.0.0.1', 'in', 1, 1),
                                       (now - 86400, 'em0', '10.0.0.2', 'in', 1, 1)])
        self.store.open_new_windows([('aa:bb:cc:dd:ee:ff', '10.0.0.1', 'em0')], old)
        self.store.see_device('aa:bb:cc:dd:ee:ff', old, False, False)
        self.store.commit()

        self.store.prune(now)
        self.store.commit()

        status = self.store.status()
        self.assertEqual(1, status['traffic_rows'])
        self.assertEqual(0, status['observations'])
        self.assertEqual(0, status['devices'])

    def test_purge_actually_empties_everything(self):
        """S14 promises this works; a promise that is not tested is a hope"""
        self.store.store_buckets('p', [(1787990400, 'em0', '10.0.0.1', 'in', 1, 1)])
        self.store.see_device('aa:bb:cc:dd:ee:ff', 1000, False, False, 'x', 'dnsmasq')
        self.store.open_new_windows([('aa:bb:cc:dd:ee:ff', '10.0.0.1', 'em0')], 1000)
        self.store.log_run('observe', 1000, True, 5, 'ok')
        self.store.commit()

        self.store.purge()

        status = self.store.status()
        self.assertEqual(0, status['devices'])
        self.assertEqual(0, status['observations'])
        self.assertEqual(0, status['traffic_rows'])
        self.assertEqual({}, status['runs'])
        self.assertEqual(SCHEMA_VERSION, status['schema_version'])

    def test_the_disk_ceiling_is_read_from_the_setting(self):
        self.store.db.execute("UPDATE setting SET value = '0' WHERE key = 'disk_ceiling_mb'")
        self.assertTrue(self.store.over_ceiling())


class MigrationTest(unittest.TestCase):
    """
    The upgrade path off a store that is already on a router.

    v1 harvested without the interface, which turned out to be the only thing
    separating a device from the far end of its own connection. Those rows are
    unusable rather than incomplete, so v2 drops them and resets the watermark;
    the next harvest refetches whatever netflow still holds, which is everything
    netflow has not already deleted.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')

    def tearDown(self):
        self.dir.cleanup()

    def build_v1(self):
        import sqlite3
        db = sqlite3.connect(self.path)
        db.execute("CREATE TABLE schema_version (version INTEGER NOT NULL)")
        for statement in MIGRATIONS[1]:
            db.execute(statement)
        db.execute("INSERT INTO schema_version(version) VALUES (1)")
        db.execute("INSERT INTO traffic_hour VALUES (1787990400, '10.0.0.1', 'in', 5, 1)")
        db.execute("INSERT INTO harvest_state VALUES ('FlowSourceAddrTotals', 1787990400)")
        db.execute("INSERT INTO device VALUES ('aa:bb:cc:dd:ee:ff', 1, 2, 0, 0, 'keep-me', 'dnsmasq')")
        db.commit()
        db.close()

    def test_v1_is_carried_forward_and_its_unusable_traffic_is_dropped(self):
        self.build_v1()

        store = Store(self.path)
        status = store.status()

        self.assertEqual(SCHEMA_VERSION, status['schema_version'])
        self.assertEqual(0, status['traffic_rows'])
        self.assertIsNone(store.last_bucket('FlowSourceAddrTotals'))

    def test_v1_devices_and_observations_are_not_touched(self):
        """only the traffic table was wrong; nobody's device history was"""
        self.build_v1()

        self.assertEqual(1, Store(self.path).status()['devices'])

    def test_the_new_table_takes_rows_with_an_interface(self):
        self.build_v1()
        store = Store(self.path)

        written = store.store_buckets(
            'FlowSourceAddrTotals', [(1787990400, 'vtnet1_vlan20', '10.0.0.1', 'in', 5, 1)]
        )

        self.assertEqual(1, written)


class CollectorSmokeTest(unittest.TestCase):
    """
    Drives the real script. Cheap, and it catches the class of mistake unit
    tests cannot see: an import that does not resolve, a schema that does not
    apply, an argument that does not parse.
    """

    def run_duty(self, duty, db):
        env = dict(os.environ, LENS_DB=db)
        return subprocess.run(
            [sys.executable, os.path.join(SCRIPTS, 'collect.py'), duty],
            capture_output=True, text=True, env=env, timeout=30,
        )

    def test_status_on_a_box_that_has_never_collected(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self.run_duty('status', os.path.join(directory, 'lens.sqlite'))

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('"devices":0', result.stdout.replace(' ', ''))

    def test_observe_runs_and_records_that_it_ran(self):
        """it will find whatever this machine's arp table holds, including none"""
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lens.sqlite')

            self.assertEqual(0, self.run_duty('observe', path).returncode)

            status = self.run_duty('status', path)
            self.assertIn('"observe"', status.stdout)

    def test_devices_is_json_the_web_side_can_decode_and_writes_no_run_row(self):
        """opening the page is a read, not an event in the collector's history"""
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lens.sqlite')

            result = self.run_duty('devices', path)

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual([], json.loads(result.stdout))
            self.assertNotIn('"devices"', self.run_duty('status', path).stdout.split('"runs"')[1])

    def test_purge_on_an_empty_store_is_not_an_error(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self.run_duty('purge', os.path.join(directory, 'lens.sqlite'))

            self.assertEqual(0, result.returncode, result.stderr)


if __name__ == '__main__':
    unittest.main()
