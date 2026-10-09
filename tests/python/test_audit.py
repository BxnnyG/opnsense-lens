"""
Known vulnerabilities in the installed packages (stage 57): `pkg audit -R
json-compact` as FreeBSD's pkg writes it (src/audit.c), read into a short list.
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

from lenslib import parse                                       # noqa: E402

# the shape of src/audit.c's raw output: pkg_count, packages by name, issues
ANSWER = json.dumps({
    'pkg_count': 2,
    'packages': {
        'curl': {'version': '8.4.0', 'issue_count': 1, 'issues': [{
            'Affected versions': ['< 8.4.0_1'],
            'description': 'curl -- SOCKS5 heap buffer overflow',
            'cve': ['CVE-2023-38545'],
            'url': 'https://vuxml.FreeBSD.org/freebsd/d6c19e8c.html'}],
            'reverse dependencies': ['opnsense']},
        'expat': {'version': '2.5.0', 'issue_count': 1, 'issues': [{
            'Affected versions': ['< 2.6.0'], 'description': 'expat -- denial of service'}]},
    },
})


class AuditTest(unittest.TestCase):

    def test_each_package_with_what_is_known(self):
        found = parse.audit_packages(ANSWER)
        self.assertEqual(['curl', 'expat'], [p['name'] for p in found])
        self.assertEqual('8.4.0', found[0]['version'])
        self.assertEqual(['CVE-2023-38545'], found[0]['issues'][0]['cves'])
        self.assertEqual([], found[1]['issues'][0]['cves'], 'an issue without a CVE is still an issue')

    def test_nothing_vulnerable_is_an_empty_list_and_nonsense_is_none(self):
        self.assertEqual([], parse.audit_packages(json.dumps({'pkg_count': 0, 'packages': {}})))
        self.assertEqual([], parse.audit_packages('{"pkg_count": 0}'))
        self.assertIsNone(parse.audit_packages(''))
        self.assertIsNone(parse.audit_packages('pkg: vulnxml file vuln.xml does not exist'))


if __name__ == '__main__':
    unittest.main()
