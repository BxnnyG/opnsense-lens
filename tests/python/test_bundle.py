"""
Several reads in one process (stage 54): the bundle answers what each single
call would, in order, and one bad read never costs the others.
"""

import base64
import io
import json
import os
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
from lenslib.store import Store                                 # noqa: E402


def encoded(calls):
    return base64.urlsafe_b64encode(json.dumps(calls).encode()).decode().rstrip('=')


class BundleTest(unittest.TestCase):

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        store = Store(self.path)
        store.see_device('aa:bb:cc:dd:ee:01', 1000, False, False, 'nas', 'dnsmasq')
        store.commit()
        store.db.close()

    def tearDown(self):
        self.dir.cleanup()

    def run_bundle(self, calls):
        out = io.StringIO()
        with mock.patch.object(collect, 'DB_PATH', self.path), redirect_stdout(out):
            collect.bundle(encoded(calls))
        return json.loads(out.getvalue())

    def test_each_read_answers_as_its_single_call_does_in_order(self):
        answer = self.run_bundle([['devices', {}], ['brief', {}], ['status', {}]])
        store = Store(self.path)
        self.assertEqual(store.devices(), answer[0])
        self.assertIsNone(answer[1]['traffic_rows'])
        self.assertEqual(0, answer[2]['traffic_rows'])
        self.assertEqual(answer[2]['devices'], answer[1]['devices'])

    def test_an_unknown_or_failing_read_is_null_and_costs_nothing_else(self):
        with mock.patch.dict(collect.READS, {'boom': lambda s, n, o: 1 / 0}):
            answer = self.run_bundle([['nonsense', {}], ['boom', {}], ['devices', {}]])
        self.assertEqual([None, None], answer[:2])
        self.assertEqual(1, len(answer[2]))

    def test_a_request_that_is_not_a_short_list_is_refused(self):
        out = io.StringIO()
        with mock.patch.object(collect, 'DB_PATH', self.path), redirect_stdout(out):
            collect.bundle('not base64 json')
        self.assertIn('error', json.loads(out.getvalue()))
        self.assertIn('error', self.run_bundle([['devices', {}]] * (collect.BUNDLE_MAX + 1)))


if __name__ == '__main__':
    unittest.main()
