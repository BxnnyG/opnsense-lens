"""
What happened, derived from the store (§4.63): each kind from hand-built rows,
the store queries behind them, and the mute kept beside the operator's label.
"""

import base64
import io
import json
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

import collect                                                  # noqa: E402
from lenslib import events                                      # noqa: E402
from lenslib.store import Store                                 # noqa: E402

DAY = 86400
MB = 1024 * 1024
NOW = 1790000000 - 1790000000 % DAY + 12 * 3600


class UnusualDaysTest(unittest.TestCase):
    def history(self, today, spike_day, spike, days=30):
        rows = [('aa', day, 100 * MB) for day in range(today - days, today + 1)]
        return [(mac, day, spike if day == spike_day else octets) for mac, day, octets in rows]

    def test_a_past_day_is_judged_on_the_days_before_it(self):
        today = 20000
        rows = self.history(today, today - 3, 900 * MB)

        found = events.unusual_days(rows, today - 7, today, 21, 4.0, 100 * MB)

        self.assertEqual(1, len(found))
        self.assertEqual((today - 3) * DAY, found[0]['day'])
        self.assertEqual(900 * MB, found[0]['octets'])
        self.assertFalse(found[0]['partial'])

    def test_today_is_marked_partial(self):
        today = 20000
        found = events.unusual_days(self.history(today, today, 900 * MB), today - 1, today, 21, 4.0, 100 * MB)

        self.assertEqual([True], [entry['partial'] for entry in found])

    def test_no_hindsight_a_day_without_enough_history_before_it_is_not_judged(self):
        today = 20000
        # only ten days of history before the spike: the evening verdict was "still learning"
        rows = self.history(today, today - 20, 900 * MB, days=30)

        self.assertEqual([], events.unusual_days(rows, today - 25, today, 21, 4.0, 100 * MB))


class GatewayRunsTest(unittest.TestCase):
    def samples(self, statuses, start=NOW - 3600, name='WAN'):
        return [(name, start + index * 300, status, 20.0 if status != 'none' else 0.0)
                for index, status in enumerate(statuses)]

    def test_two_bad_samples_are_not_news(self):
        self.assertEqual([], events.gateway_runs(self.samples(['none', 'loss', 'loss', 'none']), NOW))

    def test_three_are_and_the_run_ends_at_the_first_good_sample(self):
        runs = events.gateway_runs(self.samples(['none', 'loss', 'delay', 'loss', 'none']), NOW)

        self.assertEqual(1, len(runs))
        self.assertEqual('degraded', runs[0]['state'])
        self.assertEqual(NOW - 3600 + 300, runs[0]['from'])
        self.assertEqual(NOW - 3600 + 1200, runs[0]['to'])
        self.assertEqual(20.0, runs[0]['loss'])
        self.assertFalse(runs[0]['ongoing'])

    def test_one_down_sample_makes_the_run_down(self):
        runs = events.gateway_runs(self.samples(['loss', 'down', 'loss', 'none']), NOW)

        self.assertEqual('down', runs[0]['state'])

    def test_a_run_still_going_is_ongoing_until_now(self):
        runs = events.gateway_runs(self.samples(['none', 'loss', 'loss', 'loss'], start=NOW - 900), NOW)

        self.assertTrue(runs[0]['ongoing'])
        self.assertEqual(NOW, runs[0]['to'])

    def test_an_ongoing_run_is_found_even_when_another_gateway_follows(self):
        rows = self.samples(['loss', 'loss', 'loss'], start=NOW - 600, name='A') \
            + self.samples(['none'], start=NOW - 600, name='B')

        runs = events.gateway_runs(rows, NOW)

        self.assertEqual(['A'], [run['name'] for run in runs])
        self.assertTrue(runs[0]['ongoing'])

    def test_a_gap_in_the_samples_ends_a_run_rather_than_bridging_it(self):
        rows = self.samples(['loss', 'loss'], start=NOW - 7200) + self.samples(['loss', 'none'], start=NOW - 1800)

        self.assertEqual([], events.gateway_runs(rows, NOW))


class NewDevicesTest(unittest.TestCase):
    def test_nothing_is_new_in_the_first_two_days(self):
        rows = [('aa', 1000 + DAY), ('bb', 1000 + 3 * DAY)]

        new, new_from = events.new_devices(rows, 1000, 0)

        self.assertEqual(['bb'], [entry['mac'] for entry in new])
        self.assertEqual(1000 + 2 * DAY, new_from)

    def test_an_empty_store_has_no_news(self):
        self.assertEqual(([], None), events.new_devices([], None, 0))


class StoreTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.store = Store(self.path)

    def tearDown(self):
        self.dir.cleanup()

    def label(self, mac):
        for device in self.store.devices():
            if device['mac'] == mac:
                return device['label']
        return None

    def test_a_mute_keeps_the_name_and_a_rename_keeps_the_mute(self):
        self.store.see_device('aa:00:00:00:00:01', NOW, False, False, None, None)
        self.store.set_label('aa:00:00:00:00:01', {'name': 'NAS', 'kind': '', 'tags': '', 'note': ''}, NOW)
        self.store.set_label('aa:00:00:00:00:01', {'muted': True}, NOW)
        self.assertEqual(('NAS', True), (self.label('aa:00:00:00:00:01')['name'],
                                         self.label('aa:00:00:00:00:01')['muted']))

        self.store.set_label('aa:00:00:00:00:01', {'name': 'Backup box', 'kind': '', 'tags': '', 'note': ''}, NOW)
        self.assertEqual(('Backup box', True), (self.label('aa:00:00:00:00:01')['name'],
                                                self.label('aa:00:00:00:00:01')['muted']))

    def test_a_muted_device_without_a_name_keeps_its_row(self):
        self.store.see_device('aa:00:00:00:00:01', NOW, False, False, None, None)
        self.assertEqual('saved', self.store.set_label('aa:00:00:00:00:01', {'muted': True}, NOW))
        self.assertEqual('cleared', self.store.set_label('aa:00:00:00:00:01', {'muted': False}, NOW))
        self.assertIsNone(self.label('aa:00:00:00:00:01'))

    def test_an_overlap_is_found_with_the_moment_it_began(self):
        for mac, start in (('aa:00:00:00:00:01', NOW - 5000), ('aa:00:00:00:00:02', NOW - 3000)):
            self.store.see_device(mac, start, False, False, None, None)
            self.store.db.execute(
                "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
                " VALUES (?, '10.0.0.5', 'em1', ?, ?)", (mac, start, NOW - 1000))

        rows = [dict(row) for row in self.store.address_overlaps(NOW - DAY)]

        self.assertEqual(1, len(rows))
        self.assertEqual(NOW - 3000, rows[0]['at'])
        self.assertEqual(('aa:00:00:00:00:01', 'aa:00:00:00:00:02'), (rows[0]['one'], rows[0]['other']))

    def test_the_duty_returns_every_kind_and_bounds_its_range(self):
        self.store.see_device('aa:00:00:00:00:01', NOW - 10 * DAY, False, False, None, None)
        self.store.see_device('aa:00:00:00:00:02', NOW - DAY, False, False, None, None)
        self.store.see_device('aa:00:00:00:00:03', NOW - DAY, False, True, None, None)
        self.store.commit()

        report = collect.events(self.store, NOW, 365)

        self.assertEqual(collect.EVENT_DAYS, report['days'])
        self.assertEqual(['aa:00:00:00:00:02'], [entry['mac'] for entry in report['new']],
                         'the firewall\'s own addresses are never new')
        for key in ('unusual', 'outages', 'gateways', 'overlaps'):
            self.assertEqual([], report[key])

    def test_the_label_duty_mutes_every_mac_of_a_folded_phone_but_names_only_one(self):
        for mac in ('e6:00:00:00:00:01', 'e6:00:00:00:00:02'):
            self.store.see_device(mac, NOW, True, False, 'Pixel', 'dnsmasq')
        self.store.commit()

        def encoded(fields):
            return base64.urlsafe_b64encode(json.dumps(fields).encode()).decode().rstrip('=')

        with mock.patch.object(collect, "DB_PATH", self.path), redirect_stdout(io.StringIO()), \
                redirect_stderr(io.StringIO()):
            self.assertEqual(0, collect.label('e6:00:00:00:00:01,e6:00:00:00:00:02', encoded({'muted': True})))
            self.assertEqual(1, collect.label('e6:00:00:00:00:01,e6:00:00:00:00:02', encoded({'name': 'x'})))

        self.store = Store(self.path)
        self.assertTrue(self.label('e6:00:00:00:00:01')['muted'])
        self.assertTrue(self.label('e6:00:00:00:00:02')['muted'])


if __name__ == '__main__':
    unittest.main()
