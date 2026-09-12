#!/usr/bin/env python3
"""
Every API action and every widget endpoint is reachable by some privilege.

Core ships Scripts/dashboard-acl.sh, which this repository has no checkout of.
This is the part of that check that matters here, and it exists because the ACL
was written for two pages in stage 1 and nobody extended it when a third
controller arrived. Everything kept working for five stages because the operator
is root; a user holding exactly the Reporting: Lens privilege would have been
shown an empty page with nothing to explain it.

Usage: lint_acl.py <plugin dir>
"""

import fnmatch
import os
import re
import sys
import xml.etree.ElementTree as ET


def patterns(plugin):
    found = []
    for root, _, files in os.walk(os.path.join(plugin, 'src')):
        if not root.endswith(os.path.join('ACL')):
            continue
        for name in files:
            if not name.endswith('.xml'):
                continue
            tree = ET.parse(os.path.join(root, name))
            for pattern in tree.iter('pattern'):
                found.append((pattern.text or '').strip())
    return [p for p in found if p]


def wanted(plugin):
    """(what, why) pairs that some pattern has to match"""
    out = []

    api = os.path.join(plugin, 'src/opnsense/mvc/app/controllers')
    for root, _, files in os.walk(api):
        if os.path.basename(root) != 'Api':
            continue
        namespace = os.path.basename(os.path.dirname(root)).lower()
        for name in files:
            if not name.endswith('Controller.php'):
                continue
            controller = name[: -len('Controller.php')].lower()
            source = open(os.path.join(root, name)).read()
            for action in re.findall(r'function\s+(\w+)Action\s*\(', source):
                out.append((
                    'api/%s/%s/%s' % (namespace, controller, action.lower()),
                    '%s::%sAction' % (name, action),
                ))

    widgets = os.path.join(plugin, 'src/opnsense/www/js/widgets/Metadata')
    if os.path.isdir(widgets):
        for name in sorted(os.listdir(widgets)):
            if not name.endswith('.xml'):
                continue
            tree = ET.parse(os.path.join(widgets, name))
            for endpoint in tree.iter('endpoint'):
                out.append((
                    (endpoint.text or '').strip().lstrip('/'),
                    'widget %s' % name,
                ))

    return out


def main():
    plugin = sys.argv[1]
    known = patterns(plugin)
    missing = []

    for what, why in wanted(plugin):
        # a declared endpoint may itself be a glob; it is covered when some
        # pattern matches it, or when it matches some pattern
        if not any(fnmatch.fnmatch(what, p) or fnmatch.fnmatch(p, what) for p in known):
            missing.append((what, why))

    for what, why in missing:
        print('  no ACL pattern reaches %s  (%s)' % (what, why))

    print('acl patterns: %d, endpoints checked: %d, unreachable: %d'
          % (len(known), len(wanted(plugin)), len(missing)))

    return 1 if missing else 0


if __name__ == '__main__':
    sys.exit(main())
