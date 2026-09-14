#!/usr/local/bin/python3
"""
The Lens collector.

Two duties, one command each:

    collect.py observe    every 5 minutes -- who is on the network, over time
    collect.py harvest    every 30 minutes -- hourly per-device traffic, before
                          OPNsense deletes it 24 hours after writing it
    collect.py status     what the store holds, as JSON
    collect.py devices    every device and its address windows, as JSON
    collect.py traffic    traffic joined onto devices at bucket time, as JSON
    collect.py label      store what the operator calls one device
    collect.py device     one device's hourly history, as JSON
    collect.py identity   whether MAC-keyed identity is holding, as JSON
    collect.py segments   traffic per interface, and how much of it is named
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
import base64
import json
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from lenslib import attribute                                   # noqa: E402
from lenslib import parse                                       # noqa: E402
from lenslib.store import Store                                 # noqa: E402

# overridable so the tests can drive the real script against a temporary file
DB_PATH = os.environ.get('LENS_DB', '/var/db/lens/lens.sqlite')

# Every DHCP server OPNsense can run, because a box has whichever one it has and
# the collector cannot ask configd which (it runs inside it). A file that is not
# there costs one failed open.
LEASE_FILES = (
    ('/var/db/dnsmasq.leases', parse.parse_dnsmasq_leases, 'dnsmasq'),
    ('/var/db/kea/kea-leases4.csv', parse.parse_kea_leases, 'kea'),
    ('/var/dhcpd/var/db/dhcpd.leases', parse.parse_isc_leases, 'isc-dhcp'),
)

# core's own timeseries reader, invoked the way core's configd action does
TIMESERIES = '/usr/local/opnsense/scripts/netflow/get_timeseries.py'
PROVIDER = 'FlowSourceAddrTotals'
RESOLUTION = 3600

# How far back a harvest reaches when it has never run, or has fallen behind.
# Core keeps hourly per-device buckets for 24 hours; 23 leaves an hour of margin
# without ever asking for what is already gone.
HARVEST_WINDOW = 23 * 3600

# How far back the traffic view reaches when nothing else is asked for.
DEFAULT_TRAFFIC_HOURS = 24

# above this a per-hour chart has more bars than a screen has pixels
DAILY_ABOVE = 72

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


def segments(store, now, hours):
    """Traffic per interface over the window, and how much of it has a device."""
    since = now - hours * 3600
    rows = {}

    for row in store.interface_traffic(since):
        entry = rows.setdefault(row['interface'], {
            'interface': row['interface'],
            'sent': 0, 'received': 0, 'named': 0, 'octets': 0,
            'addresses': 0, 'hours': 0,
        })
        # 'in' entered the interface: for a device network that is upload
        entry['sent' if row['direction'] == 'in' else 'received'] += row['octets']
        entry['named'] += row['named']
        entry['octets'] += row['octets']
        entry['addresses'] = max(entry['addresses'], row['addresses'])
        entry['hours'] = max(entry['hours'], row['hours'])

    return {
        'hours': hours,
        'since': since,
        'segments': sorted(rows.values(), key=lambda r: r['octets'], reverse=True),
        'device_interfaces': sorted(store.device_interfaces()),
        'first_bucket': store.status()['first_bucket'],
    }


def device(store, now, mac, hours):
    """
    One device's hourly history: what it sent and received, hour by hour.

    Zero hours are filled in. A gap in a chart reads as "no data" and a zero
    reads as "nothing happened", and those are different statements -- the
    series says which by starting where the store's history starts, not where
    the requested window starts.
    """
    mac = parse.normalise_mac(mac or '')

    # Beyond three days an hourly chart is more bars than a screen has pixels,
    # so it is drawn per day instead. The step is reported, because a chart
    # whose bars silently change meaning is worse than one that says so.
    step = 86400 if hours > DAILY_ABOVE else 3600

    since = now - hours * 3600
    since -= since % step

    totals = {}
    for row in store.device_traffic(mac, since):
        at = row['bucket'] - (row['bucket'] % step)
        bucket = totals.setdefault(at, {'sent': 0, 'received': 0})
        # 'in' entered the interface, so the device sent it (DESIGN 1.4)
        bucket['sent' if row['direction'] == 'in' else 'received'] += row['octets']

    status = store.status()
    first = status['first_bucket']
    start = max(since, first) if first else since

    series = []
    bucket = start - (start % step)
    while bucket < now:
        totals_at = totals.get(bucket, {'sent': 0, 'received': 0})
        series.append({
            'bucket': bucket,
            'sent': totals_at['sent'],
            'received': totals_at['received'],
        })
        bucket += step

    return {
        'mac': mac,
        'hours': hours,
        'since': start,
        'series': series,
        'sent': sum(point['sent'] for point in series),
        'received': sum(point['received'] for point in series),
        'interfaces': store.device_interfaces_of(mac),
        'history_starts': first,
        'step': step,
    }


def label(mac, encoded):
    """
    Store what the operator calls a device.

    The fields arrive base64url-encoded. A device name is free text a person
    typed -- quotes, spaces, umlauts, a semicolon if they feel like it -- and it
    travels here through configd's parameter list. Encoding it removes the
    question of what that path does with punctuation instead of answering it,
    and base64url has no characters a parameter filter would object to.
    """
    if not mac or not encoded:
        print('label needs --mac and --fields', file=sys.stderr)
        return 1

    padding = '=' * (-len(encoded) % 4)
    try:
        fields = json.loads(base64.urlsafe_b64decode(encoded + padding).decode('utf-8'))
    except (ValueError, UnicodeDecodeError) as failure:
        print('unreadable label fields: %s' % failure, file=sys.stderr)
        return 1

    if not isinstance(fields, dict):
        print('label fields must be an object', file=sys.stderr)
        return 1

    store = Store(DB_PATH)
    outcome = store.set_label(parse.normalise_mac(mac), fields, int(time.time()))
    store.commit()
    print(outcome)
    return 0


def traffic(store, now, hours):
    """
    Traffic joined onto identity, at the time of the bucket.

    A read, like `devices`: it writes no run_log row. The classification lives in
    lenslib.attribute; this counts the rows and reports what the window covered,
    because a total is meaningless without knowing how much of it Lens could see.
    """
    since = now - hours * 3600
    status = store.status()
    rows = store.traffic_rows(since)
    per_mac, unattributed, worst = attribute.classify(
        rows, store.device_interfaces(), status['first_observation']
    )

    return {
        'since': since,
        'hours': hours,
        'devices': per_mac,
        'unattributed': unattributed,
        'unexplained': worst,
        'first_bucket': status['first_bucket'],
        'watching_since': status['first_observation'],
    }


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

    if not chunks:
        # Distinct from a chunk that came back empty: nothing was asked for,
        # because no whole hour has closed since the last bucket stored. The
        # two read identically as "0 buckets offered" and mean opposite things.
        return 'already up to date; no complete hour since the last bucket'

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
    parser.add_argument(
        'duty',
        choices=['observe', 'harvest', 'status', 'devices', 'traffic', 'device',
                 'identity', 'segments', 'label', 'prune', 'purge'],
    )
    parser.add_argument('--mac', help='the device to label')
    parser.add_argument('--fields', help='base64url of a JSON object of label fields')
    parser.add_argument(
        '--hours', type=int, default=DEFAULT_TRAFFIC_HOURS,
        help='how far back the traffic duty reaches (default: %d)' % DEFAULT_TRAFFIC_HOURS,
    )
    args = parser.parse_args()

    if args.duty == 'status':
        print(json.dumps(Store(DB_PATH).status()))
        return 0

    if args.duty == 'segments':
        print(json.dumps(segments(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'identity':
        print(json.dumps(Store(DB_PATH).identity_health(int(time.time()))))
        return 0

    if args.duty == 'device':
        print(json.dumps(device(Store(DB_PATH), int(time.time()), args.mac, args.hours)))
        return 0

    if args.duty == 'label':
        return label(args.mac, args.fields)

    if args.duty == 'traffic':
        print(json.dumps(traffic(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'devices':
        # A read, so it writes no run_log row: opening the page is not an event
        # in the collector's history and must not look like one.
        print(json.dumps(Store(DB_PATH).devices()))
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
