"""
Names from the box's own resolver (§4.81): within one time budget, and a name
that only spells the address out names nothing.
"""

import os
import socket
import sys
import time
import unittest
from unittest import mock

SCRIPTS = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..',
    'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'
)
sys.path.insert(0, SCRIPTS)

import collect                                                  # noqa: E402


def resolver(address):
    if address == '10.0.0.5':
        return ('pve-backup.lan.', [], [address])
    if address == '10.0.0.6':
        return ('10-0-0-6.dhcp.example', [], [address])
    if address == '10.0.0.7':
        time.sleep(2)
        return ('late.lan', [], [address])
    raise socket.herror(1, 'Unknown host')


class ReverseTest(unittest.TestCase):

    def test_names_come_back_and_echoes_and_misses_do_not(self):
        with mock.patch('socket.gethostbyaddr', side_effect=resolver):
            found = collect.reverse_names({'a': '10.0.0.5', 'b': '10.0.0.6', 'c': '10.0.0.8'})
        self.assertEqual({'a': 'pve-backup.lan'}, found)

    def test_a_slow_answer_is_dropped_within_the_budget(self):
        start = time.time()
        with mock.patch('socket.gethostbyaddr', side_effect=resolver):
            found = collect.reverse_names({'a': '10.0.0.5', 'slow': '10.0.0.7'}, budget=0.3)
        self.assertLess(time.time() - start, 1.5)
        self.assertEqual({'a': 'pve-backup.lan'}, found)

    def test_nothing_to_ask_is_nothing(self):
        self.assertEqual({}, collect.reverse_names({}))


if __name__ == '__main__':
    unittest.main()
