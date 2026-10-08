"""
What an operator may change (§4.58), and the join from the page to the duty
that reads it.

Two kinds of test. The bounds, because a setting is a way to be wrong on
purpose and the bounds are what keeps it from being absurd. And the join,
because a settings page whose values are written to one place and read from
another is a page that saves and changes nothing -- green on both sides of the
mistake (PROCESS: when a change touches two files, test the join).
"""

import base64
import io
import json
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

_TEMP = tempfile.TemporaryDirectory()
os.environ.setdefault('LENS_DB', os.path.join(_TEMP.name, 'lens.sqlite'))

import collect                                                  # noqa: E402
from lenslib import baseline                                    # noqa: E402
from lenslib import settings                                    # noqa: E402
from lenslib.store import Store                                 # noqa: E402

MB = 1024 * 1024
DAY = 86400


def encode(fields):
    return base64.urlsafe_b64encode(json.dumps(fields).encode('utf-8')).decode('ascii').rstrip('=')


class DefaultsTest(unittest.TestCase):
    def test_an_empty_table_reads_as_the_defaults_that_shipped(self):
        values = settings.load({})

        self.assertEqual(365, values['retention_days'])
        self.assertEqual(500, values['disk_ceiling_mb'])
        self.assertEqual(900, values['observation_gap'])
        self.assertTrue(values['probe_enabled'])
        self.assertTrue(values['gateway_samples'])
        self.assertEqual(
            [('Quad9', '9.9.9.9'), ('Cloudflare', '1.1.1.1'), ('Google', '8.8.8.8')],
            values['probe_targets'],
        )

    def test_the_baseline_defaults_are_the_constants_section_4_50_argued_for(self):
        """Two places holding 21 would agree today and disagree after one edit."""
        values = settings.load({})

        self.assertEqual(baseline.NEEDS_DAYS, values['baseline_days'])
        self.assertEqual(baseline.FACTOR, values['baseline_factor'])
        self.assertEqual(baseline.FLOOR, values['baseline_floor_mb'] * MB)

    def test_a_value_nobody_can_read_falls_back_instead_of_stopping_observe(self):
        values = settings.load({
            'retention_days': 'a year',
            'probe_targets': 'Quad9=nine.nine',
            'probe_enabled': 'maybe',
        })

        self.assertEqual(365, values['retention_days'])
        self.assertEqual(3, len(values['probe_targets']))
        self.assertTrue(values['probe_enabled'])

    def test_a_number_set_from_a_shell_is_honoured_even_outside_the_page_bounds(self):
        """A ceiling of 0 is a deliberate way to stop writing; the store tests use it."""
        self.assertEqual(0, settings.load({'disk_ceiling_mb': '0'})['disk_ceiling_mb'])


class BoundsTest(unittest.TestCase):
    def assertRefused(self, fields, key, code, row=None):
        clean, errors = settings.validate(fields)
        self.assertEqual({}, clean, 'nothing may be written when anything is wrong')
        self.assertEqual([code, row], errors.get(key))

    def test_every_number_has_both_edges(self):
        for key, (kind, _, low, high) in settings.SPEC.items():
            if kind not in ('int', 'float'):
                continue
            with self.subTest(key=key):
                self.assertRefused({key: low - 1}, key, 'too_small')
                self.assertRefused({key: high + 1}, key, 'too_large')
                clean, errors = settings.validate({key: low})
                self.assertEqual({}, errors)
                clean, errors = settings.validate({key: high})
                self.assertEqual({}, errors)

    def test_a_gap_shorter_than_two_observations_is_refused(self):
        """Observe runs every 300 s; one late run would split every visit in two."""
        self.assertRefused({'observation_gap': 300}, 'observation_gap', 'too_small')

    def test_text_is_not_a_number_and_a_fraction_is_not_a_day(self):
        self.assertRefused({'retention_days': 'forever'}, 'retention_days', 'not_a_number')
        self.assertRefused({'retention_days': '36.5'}, 'retention_days', 'not_whole')
        self.assertRefused({'retention_days': True}, 'retention_days', 'not_a_number')
        self.assertRefused({'baseline_factor': 'nan'}, 'baseline_factor', 'not_a_number')

    def test_the_multiple_may_be_a_fraction(self):
        clean, errors = settings.validate({'baseline_factor': '2.5'})
        self.assertEqual({}, errors)
        self.assertEqual('2.5', clean['baseline_factor'])

    def test_a_switch_is_on_or_off_and_nothing_else(self):
        self.assertEqual('0', settings.validate({'probe_enabled': False})[0]['probe_enabled'])
        self.assertEqual('1', settings.validate({'probe_enabled': '1'})[0]['probe_enabled'])
        self.assertRefused({'probe_enabled': 'sometimes'}, 'probe_enabled', 'not_a_flag')

    def test_protected_networks_are_interface_names_once_each(self):
        """§4.83: the networks a pause never touches; none is a valid answer"""
        self.assertEqual('vlan0.10,igb1', settings.validate({'pause_protected': ['vlan0.10', 'igb1', 'vlan0.10']})[0]
                         ['pause_protected'])
        self.assertEqual('', settings.validate({'pause_protected': []})[0]['pause_protected'])
        self.assertEqual('', settings.validate({'pause_protected': ''})[0]['pause_protected'])
        self.assertRefused({'pause_protected': ['vlan0.10', 'x;y']}, 'pause_protected', 'bad_name', 2)
        self.assertRefused({'pause_protected': ['if%d' % n for n in range(65)]}, 'pause_protected', 'too_many')
        self.assertEqual(['vlan0.10'], settings.load({'pause_protected': 'vlan0.10'})['pause_protected'])
        self.assertEqual([], settings.load({})['pause_protected'])

    def test_a_key_that_does_not_exist_is_said_not_ignored(self):
        self.assertRefused({'schema_version': 1}, 'schema_version', 'unknown')

    def test_one_bad_field_writes_none_of_the_good_ones(self):
        clean, errors = settings.validate({'retention_days': 30, 'disk_ceiling_mb': 1})

        self.assertEqual({}, clean)
        self.assertIn('disk_ceiling_mb', errors)
        self.assertNotIn('retention_days', errors)


class TargetsTest(unittest.TestCase):
    def targets(self, rows):
        return settings.validate({'probe_targets': rows})

    def test_the_page_sends_three_rows_and_blank_ones_are_not_targets(self):
        clean, errors = self.targets([
            {'name': 'Quad9', 'address': '9.9.9.9'},
            {'name': '', 'address': ''},
            {'name': 'Cloudflare', 'address': ' 1.1.1.1 '},
        ])

        self.assertEqual({}, errors)
        self.assertEqual('Quad9=9.9.9.9,Cloudflare=1.1.1.1', clean['probe_targets'])

    def test_at_least_one_and_at_most_three(self):
        self.assertEqual(['too_few', None], self.targets([])[1]['probe_targets'])
        four = [{'name': 'T%d' % n, 'address': '9.9.9.%d' % n} for n in range(1, 5)]
        self.assertEqual(['too_many', None], self.targets(four)[1]['probe_targets'])

    def test_a_hostname_is_refused_because_resolving_it_is_another_dependency(self):
        errors = self.targets([{'name': 'Quad9', 'address': 'dns.quad9.net'}])[1]
        self.assertEqual(['bad_address', 1], errors['probe_targets'])

    def test_ipv6_is_refused_until_its_ping_flags_have_been_seen_on_the_box(self):
        errors = self.targets([{'name': 'Quad9', 'address': '2620:fe::fe'}])[1]
        self.assertEqual(['ipv6', 1], errors['probe_targets'])

    def test_a_private_address_is_not_the_internet(self):
        errors = self.targets([{'name': 'Modem', 'address': '192.168.1.1'}])[1]
        self.assertEqual(['not_public', 1], errors['probe_targets'])

    def test_a_name_that_would_break_the_stored_list_is_refused(self):
        for name in ('Quad9;rm', 'a=b', 'a,b', '', 'x' * 25):
            with self.subTest(name=name):
                errors = self.targets([{'name': name, 'address': '9.9.9.9'}])[1]
                self.assertEqual(['bad_name', 1], errors['probe_targets'])

    def test_the_same_address_twice_is_one_target_counted_twice(self):
        errors = self.targets([
            {'name': 'A', 'address': '9.9.9.9'},
            {'name': 'B', 'address': '9.9.9.9'},
        ])[1]
        self.assertEqual(['duplicate', 2], errors['probe_targets'])

    def test_what_is_stored_reads_back_as_what_was_sent(self):
        clean, _ = self.targets([{'name': 'My ISP', 'address': '80.69.96.12'}])
        self.assertEqual([('My ISP', '80.69.96.12')],
                         settings.load(clean)['probe_targets'])


class JoinTest(unittest.TestCase):
    """The page writes through `configure`; every duty that reads must see it."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = os.path.join(self.dir.name, 'lens.sqlite')
        self.original_path = collect.DB_PATH
        collect.DB_PATH = self.path
        self.store = Store(self.path)

    def tearDown(self):
        collect.DB_PATH = self.original_path
        self.dir.cleanup()

    def configure(self, fields):
        out = io.StringIO()
        with redirect_stdout(out):
            code = collect.configure(encode(fields))
        return code, json.loads(out.getvalue())

    def reopened(self):
        return Store(self.path)

    def test_a_saved_setting_is_what_the_store_reads(self):
        code, reply = self.configure({'retention_days': 30, 'observation_gap': 1200})

        self.assertEqual(0, code)
        self.assertEqual('saved', reply['status'])
        store = self.reopened()
        self.assertEqual(30, store.setting_int('retention_days'))
        self.assertEqual(1200, store.settings()['observation_gap'])
        self.assertEqual(30, store.status()['retention_days'])

    def test_a_refused_setting_leaves_the_store_as_it_was(self):
        code, reply = self.configure({'retention_days': 30, 'disk_ceiling_mb': 1})

        # 0 on purpose: configd drops the output of a script that exits non-zero
        self.assertEqual(0, code)
        self.assertEqual('invalid', reply['status'])
        self.assertEqual(['too_small', None], reply['errors']['disk_ceiling_mb'])
        self.assertEqual([50, 20000], reply['bounds']['disk_ceiling_mb'])
        self.assertEqual(365, self.reopened().setting_int('retention_days'))

    def test_something_that_is_not_an_object_is_refused_as_such(self):
        out = io.StringIO()
        with redirect_stdout(out):
            code = collect.configure('bm90IGpzb24')
        self.assertEqual(0, code)
        self.assertEqual('invalid', json.loads(out.getvalue())['status'])

    def test_retention_from_the_page_is_what_prune_applies(self):
        now = 1790000000
        self.store.see_device('aa:bb:cc:dd:ee:01', now - 40 * DAY, False, False)
        self.store.commit()
        self.configure({'retention_days': 30})

        store = self.reopened()
        self.assertGreater(store.prune(now), 0)
        self.assertEqual(0, store.status()['devices'])

    def test_probes_switched_off_are_not_run_and_say_so(self):
        called = []
        original_probe, original_gateways = collect.probe_internet, collect.sample_gateways
        collect.probe_internet = lambda store, now, targets: called.append(targets) or '; probed'
        collect.sample_gateways = lambda store, now: called.append('gateways') or '; sampled'
        try:
            self.configure({'probe_enabled': False, 'gateway_samples': False})
            detail = collect.observe(self.reopened(), 1790000000)
        finally:
            collect.probe_internet, collect.sample_gateways = original_probe, original_gateways

        self.assertEqual([], called)
        self.assertIn('probes switched off', detail)
        self.assertIn('gateway samples switched off', detail)

    def test_probes_switched_on_go_to_the_targets_the_operator_chose(self):
        called = []
        original = collect.probe_internet
        collect.probe_internet = lambda store, now, targets: called.append(targets) or '; probed'
        try:
            self.configure({'probe_targets': [{'name': 'ISP', 'address': '80.69.96.12'}]})
            collect.observe(self.reopened(), 1790000000)
        finally:
            collect.probe_internet = original

        self.assertEqual([[('ISP', '80.69.96.12')]], called)

    def test_the_panel_does_not_read_the_last_round_before_the_switch_as_now(self):
        now = 1790000000
        self.store.store_probe_samples(now - 600, [('Quad9', None, None, 100.0)])
        self.store.commit()
        self.configure({'probe_enabled': False})

        report = collect.internet(self.reopened(), now, 24)

        self.assertFalse(report['probing'])
        self.assertEqual([], report['latest'])
        self.assertEqual([], report['targets'])

    def test_the_panel_names_the_operators_targets(self):
        self.configure({'probe_targets': [{'name': 'ISP', 'address': '80.69.96.12'}]})

        report = collect.internet(self.reopened(), 1790000000, 24)

        self.assertTrue(report['probing'])
        self.assertEqual(['ISP'], report['targets'])

    def test_the_baseline_judges_with_the_operators_guards(self):
        now = 20000 * DAY + 3600
        mac = 'aa:bb:cc:dd:ee:02'
        rows = [(mac, 20000 - n, 10 * MB) for n in range(1, 11)] + [(mac, 20000, 40 * MB)]

        original = Store.daily_totals
        Store.daily_totals = lambda self, since: [
            {'mac': m, 'day': d, 'octets': o, 'sent': o // 10} for m, d, o in rows
        ]
        try:
            before = collect.baseline(self.reopened(), now)
            self.configure({'baseline_days': 10, 'baseline_factor': 3, 'baseline_floor_mb': 20})
            after = collect.baseline(self.reopened(), now)
        finally:
            Store.daily_totals = original

        self.assertEqual([], before['unusual'], 'ten days is still learning at the default 21')
        self.assertEqual(21, before['needs_days'])
        self.assertEqual(10, after['needs_days'])
        self.assertEqual([mac], [entry['mac'] for entry in after['unusual']])
        self.assertEqual(20 * MB, after['floor'])

    def test_the_settings_duty_describes_values_defaults_and_bounds(self):
        self.configure({'retention_days': 90})

        described = settings.describe(self.reopened().stored_settings())

        self.assertEqual(90, described['values']['retention_days'])
        self.assertEqual(365, described['defaults']['retention_days'])
        self.assertEqual([7, 3650], described['bounds']['retention_days'])
        self.assertEqual([1, 3], described['bounds']['probe_targets'])
        self.assertEqual({'name': 'Quad9', 'address': '9.9.9.9'},
                         described['values']['probe_targets'][0])
        self.assertNotIn('probe_enabled', described['bounds'])

    def test_purge_forgets_what_was_collected_and_keeps_what_the_operator_set(self):
        self.store.see_device('aa:bb:cc:dd:ee:03', 1790000000, False, False)
        self.store.commit()
        self.configure({'retention_days': 30, 'probe_enabled': False})

        store = self.reopened()
        store.purge()

        self.assertEqual(0, store.status()['devices'])
        self.assertEqual(30, store.setting_int('retention_days'))
        self.assertFalse(store.settings()['probe_enabled'])


if __name__ == '__main__':
    unittest.main()
