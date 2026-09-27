"""
Who a device talks to (§4.62): what is kept, whose it is, and that nothing is
kept until the operator says so.
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
from lenslib import parse                                       # noqa: E402
from lenslib.store import Store                                 # noqa: E402

DAY = 86400
D0 = 1789948800 - 1789948800 % DAY
CAMERA, PHONE = '2c:aa:8e:40:50:60', 'e6:00:00:00:00:02'
IOT = 'vtnet1_vlan21'


def payload(day, flows):
    """flows: (if, src, dst, port, proto, direction, octets)"""
    return {str(day): {','.join([i, s, d, str(p), str(pr), dr]): {'octets': o, 'packets': 1}
                       for i, s, d, p, pr, dr, o in flows}}


class ParseTest(unittest.TestCase):
    def test_on_a_device_segment_in_is_sent_and_out_is_received(self):
        rows = parse.destinations_from_timeseries(payload(D0, [
            (IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 900),
            (IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'out', 100),
        ]), {IOT})

        self.assertEqual({(D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 900, 1),
                          (D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'out', 100, 1)}, set(rows))

    def test_the_far_end_and_loopback_are_the_same_flows_seen_from_outside(self):
        rows = parse.destinations_from_timeseries(payload(D0, [
            ('pppoe0', '52.1.2.3', '10.10.21.20', 8883, 6, 'in', 100),
            ('lo0', '127.0.0.1', '127.0.0.1', 53, 17, 'in', 5),
        ]), {IOT})

        self.assertEqual([], rows)

    def test_the_cut_keeps_the_heaviest_and_sums_the_rest_so_nothing_is_lost(self):
        flows = [(IOT, '10.10.21.20', '52.1.2.%d' % n, 443, 6, 'in', 1000 - n) for n in range(40)]
        rows = parse.destinations_from_timeseries(payload(D0, flows), {IOT}, keep=25)

        kept = [r for r in rows if r[3] != '*']
        other = [r for r in rows if r[3] == '*']
        self.assertEqual(25, len(kept))
        self.assertEqual(1, len(other))
        self.assertEqual(sum(f[6] for f in flows), sum(r[7] for r in rows))
        self.assertIn('52.1.2.0', [r[3] for r in kept], 'the heaviest is kept')

    def test_zero_filled_slices_and_broken_keys_are_not_rows(self):
        rows = parse.destinations_from_timeseries({
            str(D0): {'%s,10.10.21.20,52.1.2.3,443,6,in' % IOT: {'octets': 0},
                      'not,enough': {'octets': 5}},
            'garbage': {},
        }, {IOT})

        self.assertEqual([], rows)


class StoreTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))

    def tearDown(self):
        self.dir.cleanup()

    def window(self, mac, address, start, end, interface=IOT):
        self.store.see_device(mac, start, mac[1] in '26ae', False)
        self.store.db.execute(
            "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
            " VALUES (?, ?, ?, ?, ?)", (mac, address, interface, start, end))

    def test_a_destination_belongs_to_who_held_the_address_that_day(self):
        self.window(CAMERA, '10.10.21.20', D0 - 5 * DAY, D0 + DAY)
        self.store.store_destinations(D0, [(D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 900, 1),
                                           (D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'out', 100, 1)])

        rows = list(self.store.destinations(CAMERA, D0 - DAY))

        self.assertEqual(1, len(rows))
        self.assertEqual((900, 100, 1), (rows[0]['sent'], rows[0]['received'], rows[0]['days']))

    def test_a_day_two_devices_shared_the_address_is_refused_not_guessed(self):
        self.window(CAMERA, '10.10.21.20', D0, D0 + 3600)
        self.window(PHONE, '10.10.21.20', D0 + 7200, D0 + 9000)
        self.store.store_destinations(D0, [(D0, IOT, '10.10.21.20', '52.1.2.3', 443, 6, 'in', 900, 1)])

        self.assertEqual([], list(self.store.destinations(CAMERA, D0 - DAY)))
        self.assertEqual([], list(self.store.destinations(PHONE, D0 - DAY)))

    def test_a_destination_on_two_days_is_counted_on_two_days(self):
        self.window(CAMERA, '10.10.21.20', D0 - 5 * DAY, D0 + 2 * DAY)
        self.store.store_destinations(D0, [(D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 1, 1)])
        self.store.store_destinations(D0 + DAY, [(D0 + DAY, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'out', 1, 1)])

        self.assertEqual(2, list(self.store.destinations(CAMERA, D0 - DAY))[0]['days'])

    def test_retention_and_purge_take_destinations_with_everything_else(self):
        self.window(CAMERA, '10.10.21.20', D0, D0 + DAY)
        self.store.store_destinations(D0, [(D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 9, 1)])
        self.store.commit()

        self.store.prune(D0 + 400 * DAY)
        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM destination_day").fetchone()[0])

        self.store.store_destinations(D0, [(D0, IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 9, 1)])
        self.store.commit()
        self.store.purge()
        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM destination_day").fetchone()[0])


class HarvestTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = Store(os.path.join(self.dir.name, 'lens.sqlite'))
        self.asked = []
        self.original_details = collect.fetch_details
        self.original_hours = collect.harvest_hours
        collect.fetch_details = lambda day: self.asked.append(day) or payload(day, [
            (IOT, '10.10.21.20', '52.1.2.3', 8883, 6, 'in', 900)])
        collect.harvest_hours = lambda store, now: 'hours fine'
        self.store.see_device(CAMERA, D0 - 30 * DAY, False, False)
        self.store.db.execute(
            "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
            " VALUES (?, '10.10.21.20', ?, ?, ?)", (CAMERA, IOT, D0 - 30 * DAY, D0))

    def tearDown(self):
        collect.fetch_details = self.original_details
        collect.harvest_hours = self.original_hours
        self.dir.cleanup()

    def test_nothing_is_fetched_or_kept_until_it_is_switched_on(self):
        said = collect.harvest(self.store, D0 + 3600)

        self.assertEqual([], self.asked)
        self.assertEqual('hours fine', said)
        self.assertEqual(0, self.store.db.execute("SELECT count(*) FROM destination_day").fetchone()[0])

    def test_switched_on_it_starts_where_lens_started_watching_a_week_at_a_time(self):
        self.store.set_settings({'destinations_enabled': '1'})

        said = collect.harvest(self.store, D0 + 3600)

        self.assertEqual(collect.DESTINATIONS_DAYS_PER_RUN, len(self.asked))
        self.assertEqual(D0 - 30 * DAY, self.asked[0], 'not before the first observation')
        self.assertIn('destinations: 7 days', said)

    def test_the_next_run_resumes_and_today_is_never_asked_for(self):
        self.store.set_settings({'destinations_enabled': '1'})
        for _ in range(10):
            collect.harvest(self.store, D0 + 3600)

        self.assertEqual(len(set(self.asked)), len(self.asked), 'no day twice')
        self.assertEqual(D0 - DAY, max(self.asked), 'yesterday is the last complete day')
        self.assertIn('up to date', collect.harvest(self.store, D0 + 7200))

    def test_the_duty_says_whether_it_is_switched_on(self):
        report = collect.destinations(self.store, D0, CAMERA, 30)

        self.assertFalse(report['enabled'])
        self.assertEqual([], report['rows'])


if __name__ == '__main__':
    unittest.main()
