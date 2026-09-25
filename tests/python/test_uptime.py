"""
When the internet was there. The two ways this misleads are both about rounding
an absence away: an outage measured as zero minutes long, and a slice nobody
looked at drawn as green.
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

from lenslib import uptime                                      # noqa: E402
from lenslib.store import Store                                 # noqa: E402

ROUND = 300


class UptimeTest(unittest.TestCase):
    def test_an_outage_ends_when_something_answers_again_not_at_the_last_silent_round(self):
        """one silent round is five minutes out, not zero"""
        report = uptime.assess([(0, 0.0), (300, 100.0), (600, 0.0)], 0, 900, 3)

        self.assertEqual([[300, 600]], report['outages'])

    def test_an_outage_still_going_ends_now(self):
        report = uptime.assess([(0, 0.0), (300, 100.0)], 0, 900, 3)

        self.assertEqual([[300, 900]], report['outages'])

    def test_one_resolver_answering_is_the_internet_being_there(self):
        """the round's best loss is what counts; 33% is not down"""
        report = uptime.assess([(0, 33.3), (300, 0.0)], 0, 600, 2)

        self.assertEqual([], report['outages'])
        self.assertEqual(100.0, report['up_pct'])

    def test_a_slice_nobody_looked_at_is_a_gap_not_green(self):
        report = uptime.assess([(0, 0.0)], 0, 7200, 2)

        self.assertEqual(['up', 'none'], report['strip'])

    def test_a_slice_with_some_down_rounds_is_partial(self):
        rounds = [(n * ROUND, 100.0 if n == 3 else 0.0) for n in range(12)]
        report = uptime.assess(rounds, 0, 3600, 1)

        self.assertEqual(['partial'], report['strip'])
        self.assertEqual(round(11 / 12 * 100, 2), report['up_pct'])

    def test_no_rounds_is_unknown_not_one_hundred_percent(self):
        report = uptime.assess([], 0, 3600, 4)

        self.assertIsNone(report['up_pct'])
        self.assertEqual(['none'] * 4, report['strip'])


class ProbeStoreTest(unittest.TestCase):
    def test_a_round_is_down_only_when_every_target_lost_everything(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Store(os.path.join(directory, 'lens.sqlite'))
            store.store_probe_samples(300, [('Quad9', None, None, 100.0),
                                            ('Cloudflare', 9.0, 0.4, 0.0)])
            store.store_probe_samples(600, [('Quad9', None, None, 100.0),
                                            ('Cloudflare', None, None, 100.0)])
            store.commit()

            rounds = {r['at']: r['best_loss'] for r in store.probe_rounds(0)}

            self.assertEqual(0.0, rounds[300])
            self.assertEqual(100.0, rounds[600])

    def test_probes_are_pruned_with_everything_else(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Store(os.path.join(directory, 'lens.sqlite'))
            store.store_probe_samples(100, [('Quad9', 12.0, 1.0, 0.0)])
            store.commit()
            store.prune(9999999999)
            store.commit()

            self.assertEqual(0, store.db.execute(
                "SELECT count(*) FROM probe_sample").fetchone()[0])


if __name__ == '__main__':
    unittest.main()
