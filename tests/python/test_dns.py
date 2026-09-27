"""
What the network looks up, joined onto identity (§4.64). The shapes are the ones
core's stats.py prints at stable/26.7 (plan stage 36 §2) -- read from its source,
not captured from a box, until the router round replaces them.
"""

import os
import stat
import sys
import tempfile
import unittest
from unittest import mock

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

import collect                                                  # noqa: E402
from lenslib import dns                                         # noqa: E402
from lenslib.store import Store                                 # noqa: E402

NOW = 1790000000 - 1790000000 % 600

# stats.py totals, as handle_top() builds it: pcnt a string, or the integer 0
TOTALS = {
    'total': 1000, 'blocklist_size': 1234, 'passed': 900,
    'resolved': {'total': 300, 'pcnt': '30.00'},
    'blocked': {'total': 100, 'pcnt': '10.00'},
    'local': {'total': 0, 'pcnt': 0},
    'start_time': NOW - 86400,
    'top': {'example.com': {'total': 500, 'pcnt': '55.56'}, 'b.example': {'total': 600, 'pcnt': '66.67'}},
    'top_blocked': {'ads.example': {'total': 100, 'pcnt': '100.00', 'blocklist': 'ads', 'latest_policy_uuid': None}},
}


class TotalsTest(unittest.TestCase):
    def test_counters_and_lists_are_typed_and_sorted(self):
        totals = dns.totals(TOTALS)

        self.assertEqual(1000, totals['total'])
        self.assertEqual(10.0, totals['blocked']['pct'])
        self.assertEqual(0.0, totals['local']['pct'], 'the integer 0 core prints for nothing')
        self.assertEqual(['b.example', 'example.com'], [entry['domain'] for entry in totals['top']])
        self.assertEqual('ads', totals['top_blocked'][0]['blocklist'])

    def test_anything_else_is_unreadable_not_zero(self):
        self.assertIsNone(dns.totals(None))
        self.assertIsNone(dns.totals({'error': 'x'}))


class AttributionTest(unittest.TestCase):
    def test_slot_keys_may_be_floats(self):
        slots = dns.client_slots({'%.1f' % NOW: {'10.0.0.5': {'count': 7, 'hostname': ''}}})

        self.assertEqual([(NOW, '10.0.0.5', 7)], slots)

    def test_a_slot_goes_to_whoever_held_the_address_then(self):
        slots = [(NOW, '10.0.0.5', 7), (NOW + 3600, '10.0.0.5', 3)]
        windows = [('aa', '10.0.0.5', NOW - 100, NOW + 900), ('bb', '10.0.0.5', NOW + 3000, NOW + 4000)]

        devices, unplaced = dns.attribute(slots, windows)

        self.assertEqual(7, devices['aa']['queries'])
        self.assertEqual(3, devices['bb']['queries'], 'the holder then, not the holder now')
        self.assertEqual({}, unplaced)

    def test_a_shared_slot_and_a_slot_nobody_held_are_not_guessed(self):
        slots = [(NOW, '10.0.0.5', 7), (NOW, '10.0.0.9', 2)]
        windows = [('aa', '10.0.0.5', NOW, NOW + 300), ('bb', '10.0.0.5', NOW + 200, NOW + 600)]

        devices, unplaced = dns.attribute(slots, windows)

        self.assertEqual({}, devices)
        self.assertEqual({'10.0.0.5': 7, '10.0.0.9': 2}, unplaced)


class PiecesTest(unittest.TestCase):
    def test_two_visits_on_one_lease_are_one_stretch_when_nobody_came_between(self):
        windows = [('10.0.0.5', NOW - 5000, NOW - 4000), ('10.0.0.5', NOW - 2000, NOW - 1000)]

        self.assertEqual([('10.0.0.5', NOW - 5000, NOW - 400)], dns.pieces(windows, NOW - 86400, NOW))

    def test_a_gap_someone_else_filled_stays_a_gap(self):
        windows = [('10.0.0.5', NOW - 5000, NOW - 4000), ('10.0.0.5', NOW - 2000, NOW - 1000)]
        others = [('10.0.0.5', NOW - 3500, NOW - 3000)]

        pieces = dns.pieces(windows, NOW - 86400, NOW, others)

        self.assertEqual(2, len(pieces))
        self.assertEqual(NOW - 2000, pieces[0][1], 'newest first')

    def test_a_long_stretch_is_cut_so_the_cap_does_not_hide_the_start(self):
        pieces = dns.pieces([('10.0.0.5', NOW - 3 * 86400, NOW)], NOW - 7 * 86400, NOW, span=86400)

        self.assertEqual(3, len(pieces))
        self.assertEqual(NOW, pieces[0][2])
        self.assertEqual(NOW - 3 * 86400, pieces[-1][1])

    def test_at_most_keep(self):
        windows = [('10.0.0.%d' % n, NOW - 1000, NOW - 500) for n in range(20)]

        self.assertEqual(8, len(dns.pieces(windows, NOW - 86400, NOW)))


class DeviceQueriesTest(unittest.TestCase):
    def row(self, domain, action='Pass', at=NOW, blocklist=None):
        return {'time': at, 'domain': domain, 'action': action, 'type': 'A', 'blocklist': blocklist}

    def test_names_are_counted_blocks_marked_and_the_cap_noticed(self):
        full = [self.row('a.example.')] * dns.DETAILS_CAP
        report = dns.device_queries([full, [self.row('ads.example.', 'Block', NOW + 5, 'ads')], None])

        self.assertTrue(report['capped'])
        self.assertEqual(dns.DETAILS_CAP + 1, report['queries'])
        self.assertEqual(1, report['blocked'])
        self.assertEqual('a.example', report['domains'][0]['domain'], 'the trailing dot is not a different name')
        self.assertEqual('ads', report['domains'][1]['blocklist'])
        self.assertEqual(NOW + 5, report['last'])


class DutyTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))

    def tearDown(self):
        self.dir.cleanup()

    def script(self, body):
        path = os.path.join(self.dir.name, 'stats.py')
        with open(path, 'w') as handle:
            handle.write('#!%s\nimport sys\n%s\n' % (sys.executable, body))
        os.chmod(path, os.stat(path).st_mode | stat.S_IEXEC)
        return path

    def test_a_stats_script_that_fails_makes_the_page_unreadable_not_empty(self):
        with mock.patch.object(collect, 'UNBOUND_STATS', self.script('sys.exit(1)')):
            report = collect.dns(self.store, NOW)

        self.assertEqual((False, 'unreadable'), (report['available'], report['reason']))

    def test_a_missing_stats_script_is_the_same(self):
        with mock.patch.object(collect, 'UNBOUND_STATS', os.path.join(self.dir.name, 'nowhere.py')):
            self.assertFalse(collect.dns(self.store, NOW)['available'])

    def test_nothing_recorded_says_empty(self):
        body = ("import json\nprint(json.dumps({'total': 0, 'passed': 0, 'top': {}, 'top_blocked': {}}"
                " if sys.argv[1] == 'totals' else {}))")
        with mock.patch.object(collect, 'UNBOUND_STATS', self.script(body)):
            report = collect.dns(self.store, NOW)

        self.assertEqual((True, 'empty'), (report['available'], report['reason']))

    def test_the_device_card_asks_nothing_when_switched_off(self):
        self.store.set_settings({'dns_per_device': '0'})
        with mock.patch.object(collect, 'read_json_commands') as asked:
            self.assertEqual({'enabled': False, 'hours': 24}, collect.dns_device(self.store, NOW, 'aa:00:00:00:00:01', 24))
        asked.assert_not_called()


if __name__ == '__main__':
    unittest.main()
