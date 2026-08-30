#!/usr/local/bin/python3
"""
The Lens collector.

Two duties, one command each:

    collect.py observe    every 5 minutes -- who is on the network, over time
    collect.py harvest    every 30 minutes -- hourly per-device traffic, before
                          OPNsense deletes it 24 hours after writing it
    collect.py status     what the store holds, as JSON
    collect.py prune      apply retention
    collect.py purge      delete everything, deliberately

This script does the reading and the writing; every decision it makes lives in
lenslib.parse, which is pure and tested.

It calls no configctl. Cron invokes `configctl -d lens observe`, so this runs
inside configd, and calling back into the daemon would re-enter it. ARP, NDP and
the lease files are read directly -- exactly as tools/lens-preflight.sh does --
and core's own get_timeseries.py is invoked as a subprocess rather than through
its configd action.
"""

import argparse
import json
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from lenslib import parse                                       # noqa: E402
from lenslib.store import Store                                 # noqa: E402

# overridable so the tests can drive the real script against a temporary file
DB_PATH = os.environ.get('LENS_DB', '/var/db/lens/lens.sqlite')

LEASE_FILES = (
    ('/var/db/dnsmasq.leases', parse.parse_dnsmasq_leases, 'dnsmasq'),
    ('/var/db/kea/kea-leases4.csv', parse.parse_kea_leases, 'kea'),
)

# core's own timeseries reader, invoked the way core's configd action does
TIMESERIES = '/usr/local/opnsense/scripts/netflow/get_timeseries.py'
PROVIDER = 'FlowSourceAddrTotals'
RESOLUTION = 3600

# How far back a harvest reaches when it has never run, or has fallen behind.
# Core keeps hourly per-device buckets for 24 hours; 23 leaves an hour of margin
# without ever asking for what is already gone.
HARVEST_WINDOW = 23 * 3600

# ...but never in one request. get_timeseries.py fills every timeslice for every
# dimension key it found, and on a box with nineteen interfaces that is a very
# large object to build, hand over as JSON, and insert inside one transaction.
# The first run on the operator's second firewall did exactly that and had to be
# interrupted, leaving the store locked behind a write that never finished.
# Chunks are fetched and committed one at a time, so memory stays bounded, the
# database is never locked for long, and a run that dies keeps what it had.
HARVEST_CHUNK = 4 * 3600

# get_timeseries.py is core's, and how long it takes is a property of the box,
# not of this plugin. Exceeding this is reported as a failure, never as "no data"
COMMAND_TIMEOUT = 120


def read_command(argv, timeout=30):
    """:return: stdout, or '' when the command could not be run at all"""
    try:
        return subprocess.run(
            argv, capture_output=True, text=True, timeout=timeout, check=False
        ).stdout
    except (OSError, subprocess.SubprocessError):
        return ''


def must_read_command(argv, timeout):
    """
    Same, but a command that times out or cannot run is an error.

    The difference matters: a harvest that quietly returns nothing looks exactly
    like a harvest with nothing to collect, and the whole point of this duty is
    that what it misses cannot be collected later.
    """
    try:
        result = subprocess.run(
            argv, capture_output=True, text=True, timeout=timeout, check=False
        )
    except subprocess.TimeoutExpired:
        raise RuntimeError(
            '%s did not finish within %d seconds' % (os.path.basename(argv[0]), timeout)
        )
    except OSError as failure:
        raise RuntimeError('%s could not be run: %s' % (argv[0], failure))

    if result.returncode != 0:
        detail = (result.stderr or '').strip().splitlines()
        raise RuntimeError('%s failed: %s' % (
            os.path.basename(argv[0]), detail[-1] if detail else 'no output'
        ))

    return result.stdout


def read_file(path):
    try:
        with open(path, 'r', errors='replace') as handle:
            return handle.read()
    except OSError:
        return ''


def observe(store, now):
    """Who is on the network, and which addresses they hold right now."""
    arp = parse.parse_arp(read_command(['/usr/sbin/arp', '-an']))
    ndp = parse.parse_ndp(read_command(['/usr/sbin/ndp', '-an']))

    hostnames, sources = {}, {}
    for path, reader, name in LEASE_FILES:
        for mac, hostname in reader(read_file(path)).items():
            hostnames[mac] = hostname
            sources[mac] = name

    local = {mac for mac, _, _, permanent in arp if permanent}
    seen = [(mac, address, interface) for mac, address, interface, _ in arp]
    seen += list(ndp)

    for mac in {key[0] for key in seen}:
        store.see_device(
            mac, now,
            randomised=parse.is_randomised(mac),
            is_local=mac in local,
            hostname=hostnames.get(mac),
            source=sources.get(mac),
        )

    extend, opened = parse.fold_observations(
        seen, store.open_windows(), now, store.setting_int('observation_gap')
    )
    store.extend_windows(extend, now)
    store.open_new_windows(opened, now)

    return '%d devices, %d addresses, %d new windows' % (
        len({key[0] for key in seen}), len(set(seen)), len(opened)
    )


def fetch_chunk(start, end):
    """One window of hourly per-device traffic, as core's own reader returns it."""
    raw = must_read_command([
        TIMESERIES,
        '--provider', PROVIDER,
        '--resolution', str(RESOLUTION),
        '--start_time', str(int(start)),
        '--end_time', str(int(end)),
        '--key_fields', 'if,src_addr,direction',
    ], COMMAND_TIMEOUT)

    if not raw.strip():
        raise RuntimeError('get_timeseries.py answered with nothing')

    try:
        return json.loads(raw)
    except ValueError:
        raise RuntimeError('get_timeseries.py did not answer with JSON')


def harvest(store, now):
    """
    Hourly per-device traffic, copied out before core's 24 hour expiry.

    Resumes from the last bucket actually stored rather than from when this last
    ran: a failed run then costs nothing, and a run that stored a partial window
    is not mistaken for a complete one. Chunked, and committed per chunk, so a
    busy box neither builds one enormous object nor holds the store locked while
    it does.
    """
    last = store.last_bucket(PROVIDER)
    start = max(last + RESOLUTION, now - HARVEST_WINDOW) if last else now - HARVEST_WINDOW
    complete_before = int(now) - (int(now) % RESOLUTION)

    offered, written, chunks = 0, 0, 0

    while start < complete_before:
        end = min(start + HARVEST_CHUNK, int(now))
        rows = parse.buckets_from_timeseries(
            fetch_chunk(start, end), complete_before=complete_before, after=last
        )

        offered += len(rows)
        written += store.store_buckets(PROVIDER, rows)
        store.commit()

        chunks += 1
        start = end

    return '%d buckets offered, %d new, in %d chunks' % (offered, written, chunks)


def run(duty, worker):
    store = Store(DB_PATH)
    started = time.time()
    now = int(started)

    if duty != 'status' and store.over_ceiling():
        detail = 'store is at its %d MB ceiling; not writing' % store.setting_int('disk_ceiling_mb')
        store.log_run(duty, now, False, 0, detail)
        store.commit()
        print(detail, file=sys.stderr)
        return 1

    try:
        detail = worker(store, now)
        ok = True
    except Exception as failure:                                # noqa: BLE001
        detail, ok = str(failure), False

    took_ms = int((time.time() - started) * 1000)
    store.log_run(duty, now, ok, took_ms, detail)
    store.commit()

    print('%s: %s (%d ms)' % (duty, detail, took_ms), file=sys.stderr if not ok else sys.stdout)
    return 0 if ok else 1


def main():
    parser = argparse.ArgumentParser(description='Lens collector')
    parser.add_argument('duty', choices=['observe', 'harvest', 'status', 'prune', 'purge'])
    args = parser.parse_args()

    if args.duty == 'status':
        print(json.dumps(Store(DB_PATH).status()))
        return 0

    if args.duty == 'purge':
        Store(DB_PATH).purge()
        print('purged')
        return 0

    if args.duty == 'prune':
        return run('prune', lambda store, now: '%d rows removed' % store.prune(now))

    return run(args.duty, observe if args.duty == 'observe' else harvest)


if __name__ == '__main__':
    sys.exit(main())
