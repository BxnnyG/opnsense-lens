#!/usr/bin/env python3
"""
Refresh the service domains from v2fly's domain-list-community (§4.79).

    python3 tools/services/refresh.py            # newest commit of master
    python3 tools/services/refresh.py <sha>      # one exact commit

Reads the lists lenslib/services.py names, follows their includes, and writes
lenslib/services_domains.json with the commit it read. Run it on the
development machine and commit the result: the box never fetches anything.

What is kept of a list: plain domains and full: names (as suffixes). What is
not: regexp: and keyword: (Lens matches suffixes, never patterns) and anything
tagged @ads (an ad server is not the service). An include that is another
service's own list is left to that service; SKIPPED_LISTS are never followed.

When two services claim one suffix, the one that names it nearer -- in its own
list rather than through an include -- keeps it; at the same depth, the one
someone chose (not platform) keeps it; otherwise nobody does, and the run
says so.
"""

import json
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
LENSLIB = os.path.join(HERE, '..', '..', 'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens')
sys.path.insert(0, LENSLIB)

from lenslib import services                                    # noqa: E402

REPO = 'v2fly/domain-list-community'


def fetch(url):
    with urllib.request.urlopen(url, timeout=30) as answer:
        return answer.read().decode('utf-8')


def newest_commit():
    return json.loads(fetch('https://api.github.com/repos/%s/commits/master' % REPO))['sha']


def parse(text):
    """:return: (domains, includes) of one list file"""
    domains, includes = [], []
    for line in text.splitlines():
        line = line.split('#', 1)[0].strip()
        if not line:
            continue
        words = line.split()
        entry, tags = words[0], words[1:]
        if '@ads' in tags:
            continue
        if entry.startswith('include:'):
            includes.append(entry[len('include:'):])
        elif entry.startswith('full:'):
            domains.append(entry[len('full:'):].lower())
        elif entry.startswith('domain:'):
            domains.append(entry[len('domain:'):].lower())
        elif ':' not in entry:
            domains.append(entry.lower())
    return domains, includes


def main():
    sha = sys.argv[1] if len(sys.argv) > 1 else newest_commit()
    raw = 'https://raw.githubusercontent.com/%s/%s/data/%%s' % (REPO, sha)
    owned = {name for _, _, _, lists, _ in services.SERVICES.values() for name in lists}
    cache = {}

    def read(name):
        if name not in cache:
            cache[name] = parse(fetch(raw % name))
        return cache[name]

    claims = {}         # suffix -> [(depth, platform, key)]
    for key, (_, kind, _, lists, _) in services.SERVICES.items():
        seen, todo = set(), [(name, 0) for name in lists]
        while todo:
            name, depth = todo.pop(0)
            if name in seen:
                continue
            seen.add(name)
            domains, includes = read(name)
            for domain in domains:
                claims.setdefault(domain, []).append((depth, kind == 'platform', key))
            for include in includes:
                if include in services.SKIPPED_LISTS or (include in owned and include not in lists):
                    continue
                todo.append((include, depth + 1))

    domains, disputed = {}, []
    for suffix, claimants in sorted(claims.items()):
        keys = {key for _, _, key in claimants}
        if len(keys) == 1:
            domains[suffix] = keys.pop()
            continue
        best = min((depth, platform) for depth, platform, _ in claimants)
        winners = {key for depth, platform, key in claimants if (depth, platform) == best}
        if len(winners) == 1:
            domains[suffix] = winners.pop()
        else:
            disputed.append((suffix, sorted(keys)))

    with open(services.DOMAINS_FILE, 'w') as handle:
        json.dump({
            'source': 'https://github.com/%s' % REPO,
            'license': 'MIT, see services_domains.LICENSE',
            'commit': sha,
            'domains': domains,
        }, handle, indent=0, sort_keys=True)
        handle.write('\n')

    # MIT asks for its notice to travel with the copy: it ships beside the data
    with open(services.DOMAINS_FILE.replace('.json', '.LICENSE'), 'w') as handle:
        handle.write('services_domains.json is derived from %s at commit %s.\n\n' % (REPO, sha))
        handle.write(fetch('https://raw.githubusercontent.com/%s/%s/LICENSE' % (REPO, sha)))

    counts = {}
    for key in domains.values():
        counts[key] = counts.get(key, 0) + 1
    print('%s @ %s: %d suffixes, %d lists read' % (REPO, sha[:10], len(domains), len(cache)))
    for key in services.SERVICES:
        print('  %-16s %d' % (key, counts.get(key, 0)))
    for suffix, keys in disputed:
        print('  disputed, left out: %s %s' % (suffix, keys))


if __name__ == '__main__':
    main()
