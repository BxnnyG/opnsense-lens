"""
Which services the devices asked for (#44, §4.78): a name belongs to the
service whose suffix it ends in, on a label boundary, longest suffix first;
a name nothing matches stays a name.
"""

import json
import os
import sys
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

from lenslib import dns as dnslib                               # noqa: E402
from lenslib import services                                    # noqa: E402


class MatchTest(unittest.TestCase):

    def test_a_name_belongs_to_the_service_whose_suffix_it_ends_in(self):
        self.assertEqual('netflix', services.match('occ-0-1234.1.nflxso.net'))
        self.assertEqual('youtube', services.match('rr3---sn-4g5e6nsz.googlevideo.com.'))
        self.assertEqual('whatsapp', services.match('MMG.WhatsApp.NET'))

    def test_only_on_a_label_boundary(self):
        self.assertIsNone(services.match('notnetflix.com'))
        self.assertIsNone(services.match('netflix.com.evil.example'))

    def test_the_longest_suffix_wins(self):
        self.assertEqual('appletv', services.match('tv.apple.com'))
        self.assertEqual('apple', services.match('gateway.icloud.com'))
        self.assertEqual('youtube', services.match('youtubei.googleapis.com'))
        self.assertEqual('google', services.match('www.googleapis.com'))

    def test_a_name_nothing_matches_stays_a_name(self):
        for name in ('', '.', 'com', 'example.org', 'fritz.box', None):
            self.assertIsNone(services.match(name))

    def test_no_suffix_of_lens_own_is_claimed_by_two_services(self):
        seen = {}
        for key, (_, _, _, _, suffixes) in services.SERVICES.items():
            for suffix in suffixes:
                self.assertNotIn(suffix, seen, '%s is in %s and %s' % (suffix, seen.get(suffix), key))
                seen[suffix] = key

    def test_every_service_has_a_kind_the_page_shows_and_a_source(self):
        for key, (name, kind, icon, lists, suffixes) in services.SERVICES.items():
            self.assertIn(kind, services.KINDS, key)
            self.assertTrue(icon.startswith('fa-'), key)
            self.assertTrue(lists or suffixes, key)

    def test_the_shipped_lists_say_where_they_came_from(self):
        with open(services.DOMAINS_FILE) as handle:
            shipped = json.load(handle)
        self.assertIn('v2fly/domain-list-community', shipped['source'])
        self.assertEqual(40, len(shipped['commit']))
        self.assertTrue(shipped['license'].startswith('MIT'))
        # every service with a v2fly list got something from it
        found = set(shipped['domains'].values())
        for key, (_, _, _, lists, _) in services.SERVICES.items():
            if lists:
                self.assertIn(key, found, key)
        # what a hosting provider serves is nobody's service
        for host in ('amazonaws.com', 'cloudfront.net', 'akamaized.net', 'azureedge.net'):
            self.assertNotIn(host, shipped['domains'])

    def test_lens_own_suffixes_win_a_disagreement(self):
        self.assertEqual('youtube', services.match('youtubei.googleapis.com'))
        self.assertEqual('zdf', services.match('www.zdf.de'))


class FromDomainsTest(unittest.TestCase):

    def test_one_device_most_asked_first_and_what_matched(self):
        found, matched = services.from_domains([
            ('api.netflix.com', 4, 0), ('nflxvideo.net', 6, 0),
            ('i.instagram.com', 20, 2), ('printer.local', 50, 0)])
        self.assertEqual(['instagram', 'netflix'], [s['service'] for s in found])
        self.assertEqual(10, found[1]['queries'])
        self.assertEqual(2, found[1]['names'])
        self.assertEqual(2, found[0]['blocked'])
        self.assertEqual(30, matched)


class NetworkTest(unittest.TestCase):

    def test_devices_and_bare_addresses_are_counted_apart(self):
        rows = [
            ('10.0.0.5', 'api.netflix.com.', 3600, 5, 0, None, 5, 0),
            ('10.0.0.6', 'nflxvideo.net.', 3600, 8, 0, None, 8, 0),
            ('10.0.0.9', 'www.netflix.com.', 3600, 2, 0, None, 2, 0),
            ('10.0.0.5', 'printer.local.', 3600, 7, 0, None, 0, 7),
        ]
        windows = [('aa:aa:aa:aa:aa:01', '10.0.0.5', 0, 9000),
                   ('aa:aa:aa:aa:aa:02', '10.0.0.6', 0, 9000)]
        devices, unplaced = dnslib.by_device(rows, windows)
        net = services.network(devices, unplaced)

        self.assertEqual(22, net['total'])
        self.assertEqual(15, net['matched'])
        netflix = net['services'][0]
        self.assertEqual('netflix', netflix['service'])
        self.assertEqual(2, netflix['devices'])
        self.assertEqual(1, netflix['addresses'])
        self.assertEqual(15, netflix['queries'])
        # every asker, most first: the page folds and cuts
        self.assertEqual([8, 5, 2], [a['count'] for a in netflix['askers']])
        self.assertEqual('10.0.0.9', netflix['askers'][2]['address'])

    def test_nothing_asked_is_nothing(self):
        self.assertEqual({'services': [], 'matched': 0, 'total': 0}, services.network({}, {}))


if __name__ == '__main__':
    unittest.main()
