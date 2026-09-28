#!/usr/bin/env python3
"""
Unbound's query store for the preview, in core's schema (logger.py, stable/26.7):
seven days of the seeded household's questions. Needs the duckdb module; the
preview falls back to unbound_stats.py without it, as a box without one would.

    python3 tools/preview/unbound_db.py /tmp/unbound.duckdb
"""

import os
import random
import sys
import time

import duckdb

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from unbound_stats import ASKERS, BLOCKED                        # noqa: E402

SCHEMA = """CREATE TABLE query (uuid UUID, time INTEGER, client TEXT, family TEXT, type TEXT,
    domain TEXT, action INTEGER, source INTEGER, blocklist TEXT, rcode INTEGER,
    resolve_time_ms INTEGER, dnssec_status INTEGER, ttl INTEGER)"""


def main(path):
    if os.path.exists(path):
        os.remove(path)
    rng = random.Random(11)
    now = int(time.time())
    rows = []
    for address, (rate, domains) in ASKERS.items():
        at = now - 7 * 86400
        while at < now:
            hour = time.localtime(at).tm_hour
            busy = 1.0 if 7 <= hour <= 23 else 0.15                  # the house sleeps
            at += int(rng.expovariate(rate * busy / 3600.0)) + 1
            domain = rng.choice(['', 'www.', 'api.', 'cdn.']) + rng.choice(domains)
            blocked = domain.split('.', 1)[-1] in BLOCKED or domain in BLOCKED
            rows.append((at, address, 'IPv4', 'A', domain + '.', 1 if blocked else 0,
                         rng.choice([0, 3, 3, 3]), 'ads' if blocked else None, 0, 0, 0, 300))
    # COPY from a CSV: executemany is far too slow for a week of a household
    csv = path + '.csv'
    with open(csv, 'w') as handle:
        for row in rows:
            handle.write(','.join('' if value is None else str(value) for value in row) + '\n')
    con = duckdb.connect(path)
    con.execute(SCHEMA)
    con.execute("""COPY query (time, client, family, type, domain, action, source, blocklist,
                               rcode, resolve_time_ms, dnssec_status, ttl) FROM '%s' (HEADER false)""" % csv)
    con.close()
    os.remove(csv)
    print('%s: %d questions over 7 days' % (path, len(rows)))


if __name__ == '__main__':
    main(sys.argv[1])
