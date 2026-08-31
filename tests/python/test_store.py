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

    def test_a_label_survives_every_later_observation(self):
        self.store.see_device('aa:bb:cc:dd:ee:01', 1000, randomised=False, is_local=False,
                              hostname='old-name', source='dnsmasq')
        self.store.set_label('aa:bb:cc:dd:ee:01', {'name': 'NAS', 'tags': 'storage'}, 1000)
        self.store.see_device('aa:bb:cc:dd:ee:01', 2000, randomised=False, is_local=False,
                              hostname='renamed-by-dhcp', source='dnsmasq')
        self.store.commit()

        device = self.store.devices()[0]

        self.assertEqual('NAS', device['label']['name'])
        self.assertEqual('storage', device['label']['tags'])
        self.assertEqual('renamed-by-dhcp', device['hostname'])

    def test_clearing_every_field_removes_the_label_rather_than_storing_blanks(self):
        self.store.see_device('aa:bb:cc:dd:ee:01', 1000, randomised=False, is_local=False)
        self.store.set_label('aa:bb:cc:dd:ee:01', {'name': 'NAS'}, 1000)
        self.assertEqual('cleared', self.store.set_label(
            'aa:bb:cc:dd:ee:01', {'name': '  ', 'kind': '', 'tags': '', 'note': ''}, 2000))
        self.store.commit()

        self.assertIsNone(self.store.devices()[0]['label'])

    def test_a_label_leaves_with_the_device_it_describes(self):
        """
        It is a MAC address plus what a person wrote about it, so S14 covers it
        like everything else. An orphan would outlive the retention it was
        promised under, invisibly, because nothing else joins to that table.
        """
        self.store.see_device('aa:bb:cc:dd:ee:01', 1000, randomised=False, is_local=False)
        self.store.set_label('aa:bb:cc:dd:ee:01', {'name': 'NAS'}, 1000)
        self.store.commit()

        self.store.prune(9999999999)
        self.store.commit()

        self.assertEqual(
            0, self.store.db.execute("SELECT count(*) FROM device_label").fetchone()[0])

    def test_a_label_stays_while_its_device_is_within_retention(self):
        self.store.see_device('aa:bb:cc:dd:ee:01', 9999999000, randomised=False, is_local=False)
        self.store.set_label('aa:bb:cc:dd:ee:01', {'name': 'NAS'}, 9999999000)
        self.store.commit()

        self.store.prune(9999999999)
        self.store.commit()

        self.assertEqual('NAS', self.store.devices()[0]['label']['name'])

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

    def test_purge_takes_the_labels_too(self):
        """S14 says everything, and a name a person typed is the most personal of it"""
        self.store.see_device('aa:bb:cc:dd:ee:01', 1000, randomised=False, is_local=False)
        self.store.set_label('aa:bb:cc:dd:ee:01', {'name': 'NAS', 'note': 'in the cellar'}, 1000)
        self.store.commit()

        self.store.purge()

        self.assertEqual(
            0, self.store.db.execute("SELECT count(*) FROM device_label").fetchone()[0])

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

    def test_v2_keeps_its_traffic_and_its_windows_across_the_v3_index_swap(self):
        """the upgrade both of the operator's routers actually take"""
        import sqlite3
        db = sqlite3.connect(self.path)
        db.execute("CREATE TABLE schema_version (version INTEGER NOT NULL)")
        for version in (1, 2):
            for statement in MIGRATIONS[version]:
                db.execute(statement)
            db.execute("INSERT INTO schema_version(version) VALUES (?)", (version,))
        db.execute("INSERT INTO traffic_hour VALUES (1788080400, 'em0', '10.0.0.5', 'in', 7, 1)")
        db.execute("INSERT INTO address_observation VALUES ('aa:bb:cc:dd:ee:ff', '10.0.0.5', 'em0', 1, 2)")
        db.commit()
        db.close()

        store = Store(self.path)

        self.assertEqual(SCHEMA_VERSION, store.status()['schema_version'])
        self.assertEqual(1, store.status()['traffic_rows'])
        self.assertEqual(1, store.status()['observations'])
        self.assertEqual(
            [('address', 'interface')],
            [tuple(row[2] for row in store.db.execute("PRAGMA index_info(address_observation_by_address)"))],
        )

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

    def test_traffic_is_json_with_an_accounting_of_what_it_could_not_attribute(self):
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lens.sqlite')
            store = Store(path)
            store.store_buckets('p', [
                (1788080400, 'em0', '10.0.0.5', 'in', 100, 1),
                (1788080400, 'pppoe0', '1.1.1.1', 'out', 900, 1),
            ])
            store.db.execute(
                """INSERT INTO address_observation
                   (mac, address, interface, first_seen, last_seen)
                   VALUES ('aa:bb:cc:dd:ee:01', '10.0.0.5', 'em0', 1788080400, 1788084000)"""
            )
            store.commit()

            env = dict(os.environ, LENS_DB=path)
            result = subprocess.run(
                [sys.executable, os.path.join(SCRIPTS, 'collect.py'),
                 'traffic', '--hours', '100000'],
                capture_output=True, text=True, env=env, timeout=30,
            )

            self.assertEqual(0, result.returncode, result.stderr)
            payload = json.loads(result.stdout)
            self.assertEqual(100, payload['devices']['aa:bb:cc:dd:ee:01']['in']['octets'])
            self.assertEqual(900, payload['unattributed']['far_end']['octets'])

    def test_a_label_survives_the_trip_through_configd_with_its_punctuation(self):
        """
        A device name is free text a person typed. base64url means the answer to
        "what does configd's parameter list do with a quote" never has to be
        found out the hard way.
        """
        import base64
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lens.sqlite')
            store = Store(path)
            store.see_device('aa:bb:cc:dd:ee:01', 1000, randomised=False, is_local=False)
            store.commit()

            typed = {'name': 'Bennys "Küche"; rm -rf /', 'tags': 'wohnzimmer, lärm'}
            encoded = base64.urlsafe_b64encode(
                json.dumps(typed).encode('utf-8')).decode('ascii').rstrip('=')

            env = dict(os.environ, LENS_DB=path)
            result = subprocess.run(
                [sys.executable, os.path.join(SCRIPTS, 'collect.py'), 'label',
                 '--mac', 'AA:BB:CC:DD:EE:01', '--fields', encoded],
                capture_output=True, text=True, env=env, timeout=30,
            )

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('saved', result.stdout.strip())
            self.assertEqual(typed['name'], Store(path).devices()[0]['label']['name'])

    def test_unreadable_label_fields_are_refused_rather_than_stored_as_junk(self):
        with tempfile.TemporaryDirectory() as directory:
            env = dict(os.environ, LENS_DB=os.path.join(directory, 'lens.sqlite'))
            result = subprocess.run(
                [sys.executable, os.path.join(SCRIPTS, 'collect.py'), 'label',
                 '--mac', 'aa:bb:cc:dd:ee:01', '--fields', 'not-base64-at-all!!'],
                capture_output=True, text=True, env=env, timeout=30,
            )

            self.assertEqual(1, result.returncode)

    def test_purge_on_an_empty_store_is_not_an_error(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self.run_duty('purge', os.path.join(directory, 'lens.sqlite'))

            self.assertEqual(0, result.returncode, result.stderr)


if __name__ == '__main__':
    unittest.main()
