"""
Pausing a device (§4.74): the store's half. The firewall's alias says who is
paused now; the store remembers since when, until when and how it ended, and
an open pause outlives purge and blocks forget, because the device is still in
the alias either way.
"""

import io
import json
import os
import sqlite3
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
from lenslib.store import SCHEMA_VERSION, Store, MIGRATIONS                     # noqa: E402

DAY = 86400
NOW = 1790000000
PHONE = '9a:11:22:00:00:04'
ROTATED = 'de:ad:be:00:00:05'
TV = 'a4:77:33:00:00:03'


class PauseStoreTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.store = Store(self.path)

    def tearDown(self):
        self.store.db.close()
        self.dir.cleanup()

    def test_a_pause_is_open_until_it_ends(self):
        self.store.start_pause(PHONE, [PHONE, ROTATED], NOW, NOW + 3600)
        self.store.start_pause(TV, [TV], NOW + 10, None)

        open_ = self.store.open_pauses()
        self.assertEqual([PHONE, TV], [pause['mac'] for pause in open_])
        self.assertEqual([PHONE, ROTATED], open_[0]['macs'])
        self.assertIsNone(open_[1]['until'])

        self.assertEqual(1, self.store.end_pause(PHONE, NOW + 60, 'resumed'))
        self.assertEqual(0, self.store.end_pause(PHONE, NOW + 61, 'resumed'), 'already ended')
        self.assertEqual([TV], [pause['mac'] for pause in self.store.open_pauses()])

    def test_pausing_again_ends_the_open_pause_first(self):
        self.store.start_pause(TV, [TV], NOW, NOW + 600)
        self.store.start_pause(TV, [TV], NOW + 100, None)

        self.assertEqual(1, len(self.store.open_pauses()))
        history = self.store.pauses(NOW - DAY)
        self.assertEqual(['resumed', None], [pause['ended_how'] for pause in history[::-1]])

    def test_only_a_known_ending_is_stored(self):
        self.store.start_pause(TV, [TV], NOW, None)
        with self.assertRaises(ValueError):
            self.store.end_pause(TV, NOW, 'because')

    def test_due_pauses_are_those_whose_time_has_come(self):
        self.store.start_pause(PHONE, [PHONE], NOW, NOW + 600)
        self.store.start_pause(TV, [TV], NOW, None)

        self.assertEqual([], self.store.due_pauses(NOW + 599))
        self.assertEqual([PHONE], self.store.due_pauses(NOW + 600))

    def test_purge_keeps_a_pause_that_is_still_running(self):
        self.store.start_pause(PHONE, [PHONE], NOW - DAY, NOW - DAY + 60)
        self.store.end_pause(PHONE, NOW - DAY + 60, 'expired')
        self.store.start_pause(TV, [TV], NOW, None)
        self.store.commit()

        self.store.purge()

        self.assertEqual([TV], [pause['mac'] for pause in self.store.pauses(0)])

    def test_prune_ages_out_ended_pauses_only(self):
        old = NOW - 400 * DAY
        self.store.start_pause(PHONE, [PHONE], old, None)
        self.store.end_pause(PHONE, old + 60, 'resumed')
        self.store.start_pause(TV, [TV], old, None)

        self.store.prune(NOW)

        self.assertEqual([TV], [pause['mac'] for pause in self.store.pauses(0)])

    def test_forget_refuses_a_paused_device_and_takes_its_history_otherwise(self):
        self.store.see_device(TV, NOW - DAY, False, False, None, None)
        self.store.start_pause(TV, [TV], NOW - 100, None)

        refused = self.store.forget([TV])
        self.assertEqual(1, refused['paused'])
        self.assertEqual(1, len(self.store.devices()), 'nothing deleted')

        self.store.end_pause(TV, NOW, 'resumed')
        counts = self.store.forget(TV)
        self.assertEqual(0, counts['paused'])
        self.assertEqual(1, counts['pauses'])
        self.assertEqual([], self.store.pauses(0))

    def test_a_rotated_mac_of_a_paused_phone_counts_as_paused(self):
        self.store.start_pause(PHONE, [PHONE, ROTATED], NOW, None)
        self.assertTrue(self.store.paused_among([ROTATED]))
        self.assertTrue(self.store.paused_among(ROTATED))
        self.assertFalse(self.store.paused_among(TV))

    def test_what_is_kept_counts_pauses(self):
        self.store.start_pause(TV, [TV], NOW, None)
        self.assertEqual({'rows': 1, 'oldest': NOW}, self.store.kept()['pauses'])


class PauseMigrationTest(unittest.TestCase):
    def test_a_store_on_schema_11_gains_the_table_and_keeps_its_rows(self):
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lens.sqlite')
            db = sqlite3.connect(path)
            db.execute("CREATE TABLE schema_version (version INTEGER NOT NULL)")
            for version in sorted(MIGRATIONS):
                if version > 11:
                    break
                for statement in MIGRATIONS[version]:
                    db.execute(statement)
                db.execute("INSERT INTO schema_version(version) VALUES (?)", (version,))
            db.execute("INSERT INTO device(mac, first_seen, last_seen, randomised, is_local) "
                       "VALUES ('aa:bb:cc:dd:ee:ff', 1, 2, 0, 0)")
            db.commit()
            db.close()

            store = Store(path)
            self.assertEqual(SCHEMA_VERSION, store.status()['schema_version'])
            self.assertEqual(1, store.status()['devices'])
            self.assertEqual([], store.open_pauses())
            store.db.close()


class PauseDutyTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.patch = mock.patch.object(collect, 'DB_PATH', self.path)
        self.patch.start()

    def tearDown(self):
        self.patch.stop()
        self.dir.cleanup()

    def call(self, function, *args):
        out = io.StringIO()
        with redirect_stdout(out):
            code = function(*args)
        return code, out.getvalue().strip()

    def test_start_and_end_through_the_duties(self):
        self.assertEqual((0, 'started'), self.call(collect.pause_start, PHONE.upper(), PHONE + ',' + ROTATED, 0))
        self.assertEqual((0, 'ended'), self.call(collect.pause_end, PHONE, 'resumed'))
        self.assertEqual((0, 'none open'), self.call(collect.pause_end, PHONE, 'resumed'))

    def test_the_duties_refuse_what_is_not_a_mac(self):
        self.assertEqual(1, self.call(collect.pause_start, 'aa:bb', PHONE, 0)[0])
        self.assertEqual(1, self.call(collect.pause_start, PHONE, 'x;rm -rf', 0)[0])
        self.assertEqual(1, self.call(collect.pause_end, PHONE, 'because')[0])

    def test_an_until_in_the_past_is_until_resumed(self):
        self.call(collect.pause_start, TV, TV, 5)
        self.assertIsNone(Store(self.path).open_pauses()[0]['until'])

    def test_the_list_says_what_is_open_and_due(self):
        store = Store(self.path)
        store.start_pause(TV, [TV], 100, 200)
        store.commit()
        answer = collect.pauses(store, 300)
        self.assertEqual([TV], answer['due'])
        self.assertEqual(TV, answer['open'][0]['mac'])

    def test_observe_starts_the_php_side_only_when_a_pause_is_due(self):
        store = Store(self.path)
        script = os.path.join(self.dir.name, 'pause.php')
        open(script, 'w').close()
        with mock.patch.object(collect, 'PAUSE_SCRIPT', script), \
                mock.patch.object(collect.subprocess, 'Popen') as popen:
            self.assertFalse(collect.expire_pauses(store, NOW))
            store.start_pause(TV, [TV], NOW - 600, NOW - 1)
            self.assertTrue(collect.expire_pauses(store, NOW))
        popen.assert_called_once()
        self.assertEqual([script, 'expire'], popen.call_args[0][0])

    def test_events_carry_the_pauses(self):
        store = Store(self.path)
        store.start_pause(TV, [TV], NOW - 3600, None)
        store.end_pause(TV, NOW - 60, 'expired')
        answer = collect.events(store, NOW, 7)
        self.assertEqual('expired', answer['pauses'][0]['ended_how'])



class PauseReasonTest(unittest.TestCase):
    """Why a device was paused, in the operator's words (operator, 2026-10-09)."""

    def test_a_reason_is_one_printable_line_and_short(self):
        import base64
        import collect
        enc = lambda text: base64.urlsafe_b64encode(text.encode()).decode().rstrip('=')
        self.assertEqual('Hausaufgaben', collect.pause_reason(enc('  Hausaufgaben  ')))
        self.assertEqual('Schlafen gehen', collect.pause_reason(enc('Schlafen\n\tgehen')))
        self.assertEqual(60, len(collect.pause_reason(enc('x' * 200))))
        self.assertIsNone(collect.pause_reason('-'))
        self.assertIsNone(collect.pause_reason('%%%'))

    def test_the_store_keeps_it_with_the_pause(self):
        with tempfile.TemporaryDirectory() as folder:
            store = Store(os.path.join(folder, 'lens.sqlite'))
            store.start_pause('aa:bb:cc:dd:ee:ff', ['aa:bb:cc:dd:ee:ff'], 100, None, 'Hausaufgaben')
            self.assertEqual('Hausaufgaben', store.open_pauses()[0]['reason'])

if __name__ == '__main__':
    unittest.main()
