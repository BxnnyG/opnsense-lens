"""
The router round as a script (stage 32): the part that decides ok, look or
FAIL. The SSH and browser parts need a box; what they return is judged here,
and a verdict that is wrong is worse than no script at all.
"""

import json
import os
import sys
import time
import unittest

TOOLS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tools', 'round')
sys.path.insert(0, TOOLS)

import round as lensround                                        # noqa: E402


def sections(**overrides):
    now = int(time.time())
    base = {
        'version': '0.18_1',
        'cron': '12:*/5 * * * * (/usr/local/sbin/configctl -d lens observe) > /dev/null\n'
                '13:*/30 * * * * (/usr/local/sbin/configctl -d lens harvest) > /dev/null',
        'status': json.dumps({
            'size_mb': 7.9, 'ceiling_mb': 500, 'devices': 24, 'traffic_rows': 100,
            'schema_version': 6,
            'runs': {'observe': {'at': now - 60, 'ok': True, 'took_ms': 2100, 'detail': '3 of 3 probes answered'},
                     'harvest': {'at': now - 600, 'ok': True, 'took_ms': 600, 'detail': '64 new'}},
        }),
        'identity': json.dumps({'devices': 24, 'randomised': 3, 'overlaps': 0}),
        'baseline': json.dumps({'days': 22, 'needs_days': 21, 'unusual': []}),
        'observer': '0',
        'dns': 'Execute error',
        'ipv6': 'exit=0 seconds=2',
    }
    base.update(overrides)
    return base


class RoundTest(unittest.TestCase):
    def verdicts(self, **overrides):
        report = lensround.Report({'name': 'router-01', 'host': '10.10.10.1', 'port': '22'})
        lensround.judge_ssh(report, sections(**overrides), '0.18_1')
        return {what: (verdict, evidence) for what, verdict, evidence in report.rows}

    def test_the_boxes_are_the_ones_the_deploy_script_knows(self):
        names = [box['name'] for box in lensround.boxes()]
        self.assertIn('router-01', names)
        self.assertTrue(all(box['port'].isdigit() for box in lensround.boxes()))

    def test_a_healthy_box_is_ok_everywhere_that_matters(self):
        verdicts = self.verdicts()
        for check in ('installed version', 'crontab', 'last observe', 'last harvest', 'store', 'identity'):
            self.assertEqual('ok', verdicts[check][0], check)

    def test_an_old_package_fails(self):
        self.assertEqual('FAIL', self.verdicts(version='0.17_1')['installed version'][0])

    def test_a_crontab_without_lens_fails_as_4_25_did(self):
        self.assertEqual('FAIL', self.verdicts(cron='')['crontab'][0])

    def test_a_collector_that_stopped_fails_even_if_its_last_run_was_fine(self):
        stale = json.dumps({'runs': {'observe': {'at': int(time.time()) - 3600, 'ok': True, 'took_ms': 7,
                                                 'detail': 'fine'}}})
        verdicts = self.verdicts(status=stale)
        self.assertEqual('FAIL', verdicts['last observe'][0])
        self.assertEqual('FAIL', verdicts['last harvest'][0], 'never ran')

    def test_an_unusual_day_is_something_to_look_at_not_a_failure(self):
        baseline = json.dumps({'days': 22, 'needs_days': 21,
                               'unusual': [{'mac': 'aa:bb', 'times': 4.1}]})
        self.assertEqual('look', self.verdicts(baseline=baseline)['baseline'][0])

    def test_unbound_that_records_nothing_is_look_not_fail(self):
        verdict, evidence = self.verdicts()['Unbound qstats totals']
        self.assertEqual('look', verdict)
        self.assertIn('Execute error', evidence)
        self.assertEqual('ok', self.verdicts(dns='{"total": 3}')['Unbound qstats totals'][0])

    def test_ipv6_ping_that_hangs_past_its_deadline_is_look(self):
        self.assertEqual('ok', self.verdicts()['ping -6 -t is a deadline'][0])
        self.assertEqual('look', self.verdicts(ipv6='exit=2 seconds=30')['ping -6 -t is a deadline'][0])

    def test_the_report_never_carries_a_secret(self):
        os.environ['LENS_API_SECRET'] = 'hunter2-secret'
        try:
            report = lensround.Report({'name': 'router-01', 'host': '10.10.10.1', 'port': '22'})
            lensround.judge_ssh(report, sections(), '0.18_1')
            self.assertNotIn('hunter2-secret', report.markdown('stamp', []))
        finally:
            del os.environ['LENS_API_SECRET']


if __name__ == '__main__':
    unittest.main()
