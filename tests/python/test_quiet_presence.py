"""
Presence of quiet devices (stage 56): a gap between two windows of the same
device on the same address is bridged hour by hour where the address moved
traffic, clipped to the gap, and never when another device held the address.
"""

import os
import sys
import unittest

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

from lenslib import presence                                    # noqa: E402

H = 3600
SERVER = 'aa:00:00:00:00:01'
OTHER = 'aa:00:00:00:00:02'


class BridgeTest(unittest.TestCase):

    def test_a_quiet_hour_with_traffic_is_bridged_clipped_to_the_gap(self):
        windows = [(SERVER, '10.0.0.5', 0, 1 * H + 600), (SERVER, '10.0.0.5', 3 * H, 5 * H)]
        traffic = {'10.0.0.5': [H, 2 * H]}
        self.assertEqual([(SERVER, H + 600, 2 * H), (SERVER, 2 * H, 3 * H)],
                         sorted(presence.bridges(windows, traffic)))

    def test_an_hour_without_traffic_stays_a_gap(self):
        windows = [(SERVER, '10.0.0.5', 0, H), (SERVER, '10.0.0.5', 4 * H, 5 * H)]
        bridged = presence.bridges(windows, {'10.0.0.5': [2 * H]})
        self.assertEqual([(SERVER, 2 * H, 3 * H)], bridged)

    def test_an_address_another_device_held_in_the_gap_is_bridged_for_nobody(self):
        windows = [(SERVER, '10.0.0.5', 0, H), (OTHER, '10.0.0.5', H + 600, 2 * H),
                   (SERVER, '10.0.0.5', 3 * H, 4 * H)]
        self.assertEqual([], presence.bridges(windows, {'10.0.0.5': [H, 2 * H]}))

    def test_a_long_silence_is_a_device_that_went_away(self):
        windows = [(SERVER, '10.0.0.5', 0, H), (SERVER, '10.0.0.5', 30 * H, 31 * H)]
        self.assertEqual([], presence.bridges(windows, {'10.0.0.5': [h * H for h in range(1, 30)]}))

    def test_traffic_on_another_address_proves_nothing_here(self):
        windows = [(SERVER, '10.0.0.5', 0, H), (SERVER, '10.0.0.5', 3 * H, 4 * H)]
        self.assertEqual([], presence.bridges(windows, {'10.0.0.9': [H, 2 * H]}))

    def test_bridged_and_seen_merge_into_one_presence(self):
        windows = [(SERVER, '10.0.0.5', 0, H), (SERVER, '10.0.0.5', 2 * H, 3 * H)]
        bridged = presence.bridges(windows, {'10.0.0.5': [H]})
        found = presence.spans([(m, a, b) for m, _, a, b in windows] + bridged, 0, 3 * H)
        self.assertEqual({SERVER: [[0, 3 * H]]}, found)


if __name__ == '__main__':
    unittest.main()
