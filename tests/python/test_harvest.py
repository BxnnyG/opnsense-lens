"""
The harvest loop: chunking, resuming, and refusing to fail quietly.

The chunking exists because the first run on the operator's second firewall --
nineteen interfaces, OPNsense 26.1 -- built one enormous request, held the store
locked while inserting it, and had to be interrupted. The silence exists because
the version before that swallowed a timeout and reported "0 buckets offered",
which is indistinguishable from a quiet network and is the one failure this duty
must never hide.
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

_TEMP = tempfile.TemporaryDirectory()
os.environ['LENS_DB'] = os.path.join(_TEMP.name, 'lens.sqlite')

import collect                                                  # noqa: E402
from lenslib.store import Store                                 # noqa: E402

HOUR = 3600


class HarvestTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        self.asked = []
        self.original = collect.fetch_chunk
        collect.fetch_chunk = self.fetch

    def tearDown(self):
        collect.fetch_chunk = self.original
        self.dir.cleanup()

    def fetch(self, start, end):
        """One bucket per completed hour in the window, for one device."""
        self.asked.append((start, end))
        payload = {}
        bucket = start - (start % HOUR)
        while bucket < end:
            payload[str(bucket)] = {
                'vtnet1_vlan20,10.10.20.115,in': {'octets': 100, 'packets': 2},
                'pppoe0,142.250.185.78,out': {'octets': 0, 'packets': 0},
            }
            bucket += HOUR
        return payload

    def test_a_cold_start_is_broken_into_chunks_not_one_request(self):
        now = 1788087600 + 1800
        collect.harvest(self.store, now)

        self.assertGreater(len(self.asked), 1)
        for start, end in self.asked:
            self.assertLessEqual(end - start, collect.HARVEST_CHUNK)

    def test_every_chunk_is_committed_so_an_interrupted_run_keeps_what_it_had(self):
        now = 1788087600 + 1800
        seen = []

        def failing(start, end):
            if len(seen) >= 2:
                raise RuntimeError('get_timeseries.py did not finish within 120 seconds')
            seen.append((start, end))
            return self.fetch(start, end)

        collect.fetch_chunk = failing

        with self.assertRaises(RuntimeError):
            collect.harvest(self.store, now)

        self.assertGreater(self.store.status()['traffic_rows'], 0)
        self.assertIsNotNone(self.store.last_bucket(collect.PROVIDER))

    def test_a_second_run_resumes_from_what_was_stored(self):
        now = 1788087600 + 1800
        collect.harvest(self.store, now)
        first = self.store.status()['traffic_rows']

        self.asked = []
        detail = collect.harvest(self.store, now)

        self.assertEqual(first, self.store.status()['traffic_rows'])
        self.assertIn('up to date', detail)

    def test_the_zero_filled_padding_is_not_stored(self):
        collect.harvest(self.store, 1788087600 + 1800)

        rows = self.store.db.execute(
            "SELECT DISTINCT interface FROM traffic_hour"
        ).fetchall()
        self.assertEqual(['vtnet1_vlan20'], [row[0] for row in rows])

    def test_nothing_is_asked_for_when_there_is_no_complete_hour_yet(self):
        """a box that has been up for ten minutes has nothing to harvest"""
        self.store.store_buckets(collect.PROVIDER,
                                 [(1788087600, 'em0', '10.0.0.1', 'in', 1, 1)])
        self.store.commit()

        detail = collect.harvest(self.store, 1788087600 + 1800)

        self.assertEqual([], self.asked)
        # and it says so, rather than reporting the "0 buckets offered" that a
        # request which came back empty would also report
        self.assertIn('up to date', detail)
        self.assertNotIn('offered', detail)

    def test_a_chunk_that_comes_back_empty_still_says_it_asked(self):
        """the opposite case: flowd was asked and had nothing to give"""
        collect.fetch_chunk = lambda start, end: {}

        detail = collect.harvest(self.store, 1788087600 + 1800)

        self.assertIn('0 buckets offered', detail)
        self.assertNotIn('up to date', detail)


class MustReadCommandTest(unittest.TestCase):
    def test_a_command_that_hangs_is_an_error_not_an_empty_answer(self):
        with self.assertRaises(RuntimeError) as raised:
            collect.must_read_command(['/bin/sleep', '5'], timeout=1)

        self.assertIn('did not finish within 1 seconds', str(raised.exception))

    def test_a_command_that_is_not_there_is_an_error(self):
        with self.assertRaises(RuntimeError):
            collect.must_read_command(['/nonexistent/get_timeseries.py'], timeout=5)

    def test_a_command_that_fails_reports_what_it_said(self):
        with self.assertRaises(RuntimeError) as raised:
            collect.must_read_command(['/bin/sh', '-c', 'echo boom >&2; exit 3'], timeout=5)

        self.assertIn('boom', str(raised.exception))

    def test_a_command_that_works_returns_its_output(self):
        self.assertEqual('hi\n', collect.must_read_command(['/bin/echo', 'hi'], timeout=5))


if __name__ == '__main__':
    unittest.main()
