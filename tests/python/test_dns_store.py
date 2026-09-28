"""
DNS from Unbound's own store (§4.70): a DuckDB file in core's schema
(logger.py, stable/26.7), read through core's own duckdb_helper. Skipped where
the duckdb module is not installed; the box has it, core ships it.
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
from lenslib.store import Store                                 # noqa: E402

try:
    import duckdb
except ImportError:
    duckdb = None

# core's helper, from the checkout the preview uses, or a copy of its one class
CORE_SITE_PYTHON = os.environ.get('OPNSENSE_CORE', '/home/user/opnsense/core') + '/src/opnsense/site-python'

HOUR = 3600
NOW = 1790000000 - 1790000000 % HOUR

# logger.py's CREATE TABLE, verbatim in its columns
SCHEMA = """CREATE TABLE query (uuid UUID, time INTEGER, client TEXT, family TEXT, type TEXT,
    domain TEXT, action INTEGER, source INTEGER, blocklist TEXT, rcode INTEGER,
    resolve_time_ms INTEGER, dnssec_status INTEGER, ttl INTEGER)"""


@unittest.skipUnless(duckdb and os.path.exists(CORE_SITE_PYTHON + '/duckdb_helper.py'),
                     'needs the duckdb module and core site-python')
class StoreDnsTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.db = os.path.join(self.dir.name, 'unbound.duckdb')
        con = duckdb.connect(self.db)
        con.execute(SCHEMA)
        rows = []
        # 10.0.0.5 was the TV for the last 5 hours and the phone before that
        for n in range(8):
            at = NOW - (n + 1) * HOUR + 60
            rows.append((at, '10.0.0.5', 'netflix.com.', 0, 0, None))
            rows.append((at + 1, '10.0.0.5', 'ads.example.', 1, 3, 'ads'))
        rows.append((NOW - 30 * HOUR, '10.0.0.5', 'old.example.', 0, 0, None))
        rows.append((NOW - 2 * HOUR, '10.0.0.9', 'nobody.example.', 0, 0, None))
        con.executemany("INSERT INTO query (time, client, domain, action, source, blocklist) VALUES (?, ?, ?, ?, ?, ?)",
                        rows)
        con.close()

        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        for mac, first, last in (('tv', NOW - 5 * HOUR, NOW), ('phone', NOW - 9 * HOUR, NOW - 5 * HOUR - 1)):
            self.store.see_device(mac, first, False, False, None, None)
            self.store.record_window(mac, '10.0.0.5', 'em0', first, last)
        self.store.commit()

        self.saved = collect.UNBOUND_DB, collect.SITE_PYTHON
        collect.UNBOUND_DB, collect.SITE_PYTHON = self.db, CORE_SITE_PYTHON

    def tearDown(self):
        collect.UNBOUND_DB, collect.SITE_PYTHON = self.saved
        self.dir.cleanup()

    def test_every_question_lands_on_whoever_held_the_address_that_hour(self):
        report = collect.dns(self.store, NOW, 24)

        self.assertEqual('store', report['source'])
        by_mac = {entry['mac']: entry for entry in report['devices']}
        self.assertEqual(10, by_mac['tv']['queries'])
        self.assertEqual(5, by_mac['tv']['blocked'])
        self.assertEqual(6, by_mac['phone']['queries'], 'the older hours were the phone\'s')
        self.assertEqual({'ads.example', 'netflix.com'}, {d['domain'] for d in by_mac['tv']['domains']},
                         'the trailing dot is not part of the name')
        self.assertNotIn('old.example', [d['domain'] for d in by_mac['tv']['domains']], 'outside the range')
        self.assertEqual([None], [c['mac'] for c in report['clients'] if '10.0.0.9' in c['addresses']])

    def test_the_figures_and_the_names_come_from_the_same_rows(self):
        report = collect.dns(self.store, NOW, 24)

        self.assertEqual(17, report['totals']['total'])
        self.assertEqual(8, report['totals']['blocked']['total'])
        self.assertEqual('ads.example', report['totals']['top_blocked'][0]['domain'])
        ads = [row for row in report['names'] if row['domain'] == 'ads.example'][0]
        self.assertEqual({'tv', 'phone'}, {asker['mac'] for asker in ads['askers']})

    def test_a_device_card_counts_only_its_own_hours_and_draws_a_week(self):
        card = collect.dns_device(self.store, NOW, 'tv', 24)

        self.assertEqual('store', card['source'])
        self.assertEqual(10, card['queries'])
        self.assertFalse(card['capped'])
        self.assertEqual(10, sum(cell[2] for cell in card['heatmap']))

    def test_no_module_falls_back_to_stats_py(self):
        collect.SITE_PYTHON = os.path.join(self.dir.name, 'nowhere')
        sys.modules.pop('duckdb_helper', None)
        saved_path = list(sys.path)
        sys.path[:] = [p for p in sys.path if p != CORE_SITE_PYTHON]
        try:
            self.assertIsNone(collect.unbound_rows(NOW - HOUR))
        finally:
            sys.path[:] = saved_path


if __name__ == '__main__':
    unittest.main()
