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
    collect.py baseline   which devices are doing something unusual today
    collect.py timeline   traffic on your own segments over time
    collect.py presence   when each device was here, as spans
    collect.py profile    one device: its week as a heatmap, and every address it held
    collect.py heatmap    the whole network's week as a heatmap
    collect.py gateways   latency, jitter and loss per gateway over time
    collect.py internet   round trips to public resolvers, uptime and outages
    collect.py settings   what the operator may change, with defaults and bounds
    collect.py configure  store what the operator changed, or say why not
    collect.py segments   traffic per interface, and how much of it is named
    collect.py destinations  who one device talked to, per destination and port
    collect.py moment     what one device's traffic in one slice was made of
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
from lenslib import baseline as baselib                         # noqa: E402
from lenslib import dns as dnslib                               # noqa: E402
from lenslib import events as eventlib                          # noqa: E402
from lenslib import parse                                       # noqa: E402
from lenslib import presence as presencelib                     # noqa: E402
from lenslib import settings as settingslib                     # noqa: E402
from lenslib import uptime                                      # noqa: E402
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

# core's own reader of Unbound's query store (§4.64); the preview points it at a
# stand-in that answers in the same shapes
UNBOUND_STATS = os.environ.get('LENS_UNBOUND_STATS', '/usr/local/opnsense/scripts/unbound/stats.py')
UNBOUND_TIMEOUT = 30

# Unbound's own query store, and core's helper for reading it beside the logger
# that writes it (§4.70); the preview and the tests point both elsewhere
UNBOUND_DB = os.environ.get('LENS_UNBOUND_DB', '/var/unbound/data/unbound.duckdb')
SITE_PYTHON = os.environ.get('LENS_SITE_PYTHON', '/usr/local/opnsense/site-python')
DNSBL_SIZE = '/var/unbound/data/dnsbl.size'

# every question since `since`, summed per client, name and hour -- the grain
# the attribution works at. `action` 1 is a block, `source` 0 recursion and
# 1 or 2 an answer from Unbound's own data (stats.py, stable/26.7)
UNBOUND_SQL = """
    SELECT client, domain, (time // 3600) * 3600 AS hour, count(*) AS questions,
           count(*) FILTER (WHERE action = 1) AS blocked, max(blocklist) AS blocklist,
           count(*) FILTER (WHERE source = 0) AS resolved,
           count(*) FILTER (WHERE source IN (1, 2)) AS answered_here
    FROM query
    WHERE time >= ? %s
    GROUP BY client, domain, hour
"""
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


def read_json_commands(commands, timeout, parallel=4):
    """
    Several commands at once, at most `parallel` running: each stats.py call
    spends most of its second importing pandas, and in sequence eight of them
    would keep a page waiting for the sum.

    :return: decoded JSON per command, None for one that failed or timed out
    """
    results = [None] * len(commands)
    for offset in range(0, len(commands), parallel):
        running = []
        for index, argv in enumerate(commands[offset:offset + parallel], offset):
            try:
                running.append((index, subprocess.Popen(
                    argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)))
            except OSError:
                continue
        for index, process in running:
            try:
                out, _ = process.communicate(timeout=timeout)
                results[index] = json.loads(out) if process.returncode == 0 else None
            except subprocess.TimeoutExpired:
                process.kill()
                process.communicate()
            except ValueError:
                pass
    return results


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

    conf = store.settings()
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
        seen, store.open_windows(), now, conf['observation_gap']
    )
    store.extend_windows(extend, now)
    store.open_new_windows(opened, now)

    # Both are switchable (§4.58), and a switched-off one says so in the run's
    # own detail, so "not sampled" is never mistaken for "could not sample".
    gateways_said = (sample_gateways(store, now) if conf['gateway_samples']
                     else '; gateway samples switched off')
    probes_said = (probe_internet(store, now, conf['probe_targets']) if conf['probe_enabled']
                   else '; probes switched off')

    return '%d devices, %d addresses, %d new windows%s%s' % (
        len({key[0] for key in seen}), len(set(seen)), len(opened),
        gateways_said, probes_said
    )


# three echoes each, and ping gives up on its own after this many seconds
PROBE_COUNT = 3
PROBE_DEADLINE = 4


def probe_internet(store, now, targets):
    """
    Round trips to the public resolvers, all at once.

    In parallel, so the observation run grows by one deadline rather than one
    per target. At most nine small packets every five minutes; stated in §4.57
    because it is the first traffic this plugin sends rather than reads. The
    targets are the operator's (§4.58), three by default.
    """
    # FreeBSD's ping: -t is a deadline in seconds there and a TTL on Linux, so
    # anywhere else these flags would send packets that die four hops out --
    # and a test suite run on a laptop must not ping the internet at all
    if not sys.platform.startswith('freebsd'):
        return '; probes skipped, not FreeBSD'

    running = []
    for name, address in targets:
        try:
            running.append((name, subprocess.Popen(
                ['/sbin/ping', '-c', str(PROBE_COUNT), '-t', str(PROBE_DEADLINE), '-q', address],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
            )))
        except OSError:
            continue

    rows = []
    for name, process in running:
        try:
            out, _ = process.communicate(timeout=PROBE_DEADLINE + 2)
        except subprocess.TimeoutExpired:
            process.kill()
            out = ''
        rtt, stddev, loss = parse.parse_ping(out)
        rows.append((name, rtt, stddev, 100.0 if loss is None else loss))

    if not rows:
        return '; probes could not run'

    store.store_probe_samples(now, rows)
    answered = sum(1 for _, rtt, _, _ in rows if rtt is not None)
    return '; %d of %d probes answered' % (answered, len(rows))


# core's own reader of what dpinger measured; run directly, because this runs
# inside configd and must not call back into it
GATEWAY_STATUS = '/usr/local/opnsense/scripts/routes/gateway_status.php'


def sample_gateways(store, now):
    """
    Record every gateway's latency, jitter and loss beside the observation.

    Secondary to identity, so a failure here never costs the observation -- but
    it is not swallowed either: the run's own detail says the gateways could not
    be read, which is where stage 4 learned to put such things (§4.56).
    """
    try:
        rows = parse.parse_gateway_status(must_read_command([GATEWAY_STATUS], timeout=30))
    except RuntimeError as failure:
        return '; gateways unreadable: %s' % str(failure)[:120]

    store.store_gateway_samples(now, rows)
    return '; %d gateways sampled' % len(rows)


# Four weeks: every hour of the week gets four samples, which is the fewest that
# makes a weekly pattern visible rather than one busy Tuesday.
HEATMAP_DAYS = 28


def internet(store, now, hours):
    """
    The public resolvers over the window, and whether anything answered.

    Probes switched off is its own answer: no targets and no latest round, so
    nothing reads the last round before the switch as the internet now. The
    history stays -- it was measured -- and the strip goes grey from the switch
    on, because nobody looked.
    """
    conf = store.settings()
    probing = conf['probe_enabled']
    step = 86400 if hours > DAILY_ABOVE else 3600
    since = now - hours * 3600
    slice_start = since - since % step

    series = {}
    for row in store.probe_series(slice_start, step):
        series.setdefault(row['target'], []).append({
            'at': row['slot'],
            'rtt': round(row['rtt'], 1) if row['rtt'] is not None else None,
            'loss': round(row['loss'], 1) if row['loss'] is not None else None,
        })

    latest = [{'target': r['target'],
               'rtt': round(r['rtt'], 1) if r['rtt'] is not None else None,
               'stddev': round(r['stddev'], 1) if r['stddev'] is not None else None,
               'loss': r['loss'], 'at': r['at']}
              for r in store.latest_probes()] if probing else []

    rounds = [(r['at'], r['best_loss']) for r in store.probe_rounds(since)]

    # the strip: hourly slices for a day, four-hourly for a week, daily beyond
    slots = hours if hours <= 24 else (hours // 4 if hours <= 168 else hours // 24)

    return {
        'hours': hours,
        'step': step,
        'since': since,
        'now': now,
        'probing': probing,
        'targets': [name for name, _ in conf['probe_targets']] if probing else [],
        'series': series,
        'latest': latest,
        'uptime': uptime.assess(rounds, since, now, slots),
    }


def gateways(store, now, hours):
    """Each gateway's quality over the window, one slice per hour or per day."""
    step = 86400 if hours > DAILY_ABOVE else 3600
    since = now - hours * 3600
    since -= since % step

    series = {}
    for row in store.gateway_series(since, step):
        series.setdefault(row['name'], []).append({
            'at': row['slot'],
            'delay': round(row['delay'], 1) if row['delay'] is not None else None,
            'stddev': round(row['stddev'], 1) if row['stddev'] is not None else None,
            'loss': round(row['loss'], 1) if row['loss'] is not None else None,
            'samples': row['samples'],
        })

    return {'hours': hours, 'step': step, 'since': since, 'gateways': series,
            'sampling': store.settings()['gateway_samples']}


def mac_list(raw):
    """
    One MAC, or several separated by commas: a device folded from rotating
    private addresses is every one of them (§4.61).

    :return: list of normalised MACs, never empty (an unknown MAC finds nothing)
    """
    macs = [parse.normalise_mac(part) for part in (raw or '').split(',') if part.strip()]
    return macs or ['']


def profile(store, now, mac):
    """Everything the device page needs that the list does not already have."""
    macs = mac_list(mac)
    mac = macs[0]
    since = now - HEATMAP_DAYS * 86400

    return {
        'mac': mac,
        'macs': macs,
        'heatmap': [[r['dow'], r['hour'], r['octets']] for r in store.device_heatmap(macs, since)],
        'windows': [
            {'address': r['address'], 'interface': r['interface'],
             'first_seen': r['first_seen'], 'last_seen': r['last_seen']}
            for r in store.device_windows(macs)
        ],
        'heatmap_days': HEATMAP_DAYS,
    }


def heatmap(store, now):
    """The network's week."""
    since = now - HEATMAP_DAYS * 86400

    return {
        'heatmap': [[r['dow'], r['hour'], r['octets']] for r in store.network_heatmap(since)],
        'heatmap_days': HEATMAP_DAYS,
        'first_bucket': store.status()['first_bucket'],
    }


def presence(store, now, hours):
    """Every device's presence across the window, merged and clipped."""
    since = now - hours * 3600

    # the chart cannot start before Lens did; otherwise every device looks
    # absent for the part of the window nobody was watching
    watching = store.status()['first_observation']
    start = max(since, watching) if watching else since

    windows = [(r['mac'], r['first_seen'], r['last_seen'])
               for r in store.presence_windows(start)]
    found = presencelib.spans(windows, start, now)

    return {
        'since': since,
        'start': start,
        'now': now,
        'hours': hours,
        'devices': {mac: {'spans': s, 'seconds': presencelib.seconds(s)}
                    for mac, s in found.items()},
    }


def timeline(store, now, hours):
    """The whole network over the window, one slice per hour or per day."""
    step = 86400 if hours > DAILY_ABOVE else 3600
    since = now - hours * 3600
    since -= since % step

    totals = {}
    for row in store.network_timeline(since, step):
        slot = totals.setdefault(row['at'], {'sent': 0, 'received': 0})
        # on a device segment 'in' entered the interface: the devices sent it
        slot['sent' if row['direction'] == 'in' else 'received'] += row['octets']

    first = store.status()['first_bucket']
    start = max(since, first - (first % step)) if first else since

    series = []
    at = start
    while at < now:
        slot = totals.get(at, {'sent': 0, 'received': 0})
        series.append({'at': at, 'sent': slot['sent'], 'received': slot['received']})
        at += step

    return {'hours': hours, 'step': step, 'series': series, 'first_bucket': first}


def baseline(store, now):
    """Which devices are moving far more today than they usually do."""
    today = now // 86400
    conf = store.settings()

    # a day more than the baseline needs, so the median always has a full set
    since = (today - conf['baseline_days'] - 1) * 86400

    rows = [(row['mac'], row['day'], row['octets'], row['sent']) for row in store.daily_totals(since)]

    # No names here. A device's name depends on the vendor table, which is read
    # at display time (§4.23); resolving it here gave a Proxmox guest one name in
    # the list and a bare MAC in the verdict. DeviceReport names it, once.
    report = baselib.assess_both(
        rows, today,
        needs_days=conf['baseline_days'],
        factor=conf['baseline_factor'],
        floor=conf['baseline_floor_mb'] * settingslib.MB,
    )
    report['factor'] = conf['baseline_factor']
    report['floor'] = conf['baseline_floor_mb'] * settingslib.MB
    return report


# Events reach back at most this far: the unusual days behind them cost the
# attribution join over EVENT_DAYS + baseline_days + 1 days (§4.63, rule 7)
EVENT_DAYS = 30


def events(store, now, days):
    """
    What happened over the last `days` days, per kind, in the store's own terms
    (§4.63). Nothing is written; PHP puts the kinds on one time line.
    """
    days = max(1, min(EVENT_DAYS, int(days or 7)))
    since = now - days * 86400
    today = now // 86400
    conf = store.settings()

    watching_since = store.watching_since()
    new, new_from = eventlib.new_devices(
        [(row['mac'], row['first_seen']) for row in store.devices_since(since)],
        watching_since, since)

    # the baseline window before the first day, so that day is judged as it was
    first_day = since // 86400
    totals = [(row['mac'], row['day'], row['octets'], row['sent'])
              for row in store.daily_totals((first_day - conf['baseline_days'] - 1) * 86400)]
    floor = conf['baseline_floor_mb'] * settingslib.MB
    unusual = eventlib.unusual_days(totals, first_day, today, conf['baseline_days'],
                                    conf['baseline_factor'], floor)

    rounds = [(row['at'], row['best_loss']) for row in store.probe_rounds(since)]
    outages = [{'from': start, 'to': end, 'ongoing': end == now}
               for start, end in uptime.assess(rounds, since, now, 1)['outages']]

    samples = [(row['name'], row['at'], row['status'], row['loss']) for row in store.gateway_states(since)]

    return {
        'days': days,
        'since': since,
        'now': now,
        'watching_since': watching_since,
        'new_from': new_from,
        'new': new,
        'unusual': unusual,
        'baseline': {'needs_days': conf['baseline_days'], 'factor': conf['baseline_factor'], 'floor': floor},
        'outages': outages,
        'probing': conf['probe_enabled'],
        'gateways': eventlib.gateway_runs(samples, now),
        'sampling': conf['gateway_samples'],
        'overlaps': [{'address': row['address'], 'interface': row['interface'],
                      'macs': [row['one'], row['other']], 'at': row['at']}
                     for row in store.address_overlaps(since)],
    }


def unbound_rows(since, clients=None):
    """
    Unbound's questions since `since` (§4.70), through core's own duckdb_helper,
    read-only and for one query -- the writer, Unbound's logger, waits for that
    long and no longer.

    :return: list of UNBOUND_SQL rows; None when the store cannot be read here
             (no DuckDB module, no helper), which sends the caller to stats.py
    """
    if not os.path.exists(UNBOUND_DB):
        return None
    if SITE_PYTHON not in sys.path:
        sys.path.insert(0, SITE_PYTHON)
    try:
        from duckdb_helper import DbConnection
    except ImportError:
        return None

    where, args = '', [int(since)]
    if clients is not None:
        if not clients:
            return []
        where = 'AND client IN (%s)' % ','.join('?' * len(clients))
        args += list(clients)
    try:
        with DbConnection(UNBOUND_DB, read_only=True) as db:
            if db is None or db.connection is None or not db.table_exists('query'):
                return []
            return db.connection.execute(UNBOUND_SQL % where, args).fetchall()
    except Exception:                                               # noqa: BLE001 - any failure falls back
        return None


def blocklist_size():
    try:
        with open(DNSBL_SIZE) as handle:
            return int(handle.readline() or 0)
    except (OSError, ValueError):
        return 0


def dns(store, now, hours=24):
    """
    What the network looked up. From Unbound's own store when it can be read
    (§4.70): every question of the range, by device and by name. Otherwise from
    stats.py (§4.64): its totals, and the last day's busiest clients.
    Nothing is stored either way.
    """
    hours = 168 if int(hours or 24) > 24 else 24
    since = now - hours * 3600
    rows = unbound_rows(since)
    if rows is not None:
        clients = sorted({row[0] for row in rows})
        windows = store.address_windows(clients, since - 3600, now)
        devices, unplaced = dnslib.by_device(rows, windows)
        totals = dnslib.totals_from_rows(rows, blocklist_size())
        return {
            'available': True,
            'source': 'store',
            'reason': None if totals['total'] else 'empty',
            'now': now,
            'since': since,
            'hours': hours,
            'totals': totals,
            'clients': sorted(
                [{'mac': mac, 'queries': e['queries'], 'addresses': sorted(e['addresses'])}
                 for mac, e in devices.items()]
                + [{'mac': None, 'queries': e['queries'], 'addresses': [client]}
                   for client, e in unplaced.items()],
                key=lambda entry: -entry['queries']),
            'devices': sorted([dict(dnslib.device_summary(e), mac=mac) for mac, e in devices.items()],
                              key=lambda entry: -entry['queries'])[:40],
            'names': dnslib.names_by_askers(devices, unplaced),
            'clients_read': True,
        }
    return dns_from_stats(store, now)


def dns_from_stats(store, now):
    """The stats.py path (§4.64): totals over what Unbound keeps, and the last day's clients."""
    totals_raw, clients_raw = read_json_commands([
        [UNBOUND_STATS, 'totals', '--max', '15'],
        [UNBOUND_STATS, 'rolling', '--interval', str(dnslib.SLOT), '--timeperiod', '24', '--clients'],
    ], UNBOUND_TIMEOUT)

    totals = dnslib.totals(totals_raw)
    if totals is None:
        return {'available': False, 'reason': 'unreadable', 'now': now}

    slots = dnslib.client_slots(clients_raw)
    since = now - 86400
    windows = store.address_windows([address for _, address, _ in slots], since - dnslib.SLOT, now)
    devices, unplaced = dnslib.attribute(slots, windows)

    return {
        'available': True,
        'reason': None if totals['total'] else 'empty',
        'now': now,
        'since': since,
        'totals': totals,
        'clients': sorted(
            [{'mac': mac, 'queries': entry['queries'], 'addresses': entry['addresses']}
             for mac, entry in devices.items()]
            + [{'mac': None, 'queries': count, 'addresses': [address]} for address, count in unplaced.items()],
            key=lambda entry: -entry['queries']),
        'clients_read': clients_raw is not None,
        'source': 'stats',
        'hours': 24,
    }


def dns_device(store, now, mac, hours):
    """
    What one device looked up (§4.64): each address it held in the range, asked
    of Unbound for exactly the time it held it.
    """
    if not store.settings()['dns_per_device']:
        return {'enabled': False, 'hours': hours}

    hours = 168 if int(hours or 24) > 24 else 24
    since = now - hours * 3600
    macs = mac_list(mac)
    own = [(row['address'], row['first_seen'], row['last_seen']) for row in store.device_windows(macs)]
    others = [(address, first_seen, last_seen)
              for other, address, first_seen, last_seen
              in store.address_windows([window[0] for window in own], since, now) if other not in macs]
    addresses = sorted({window[0] for window in own})

    # every question, from Unbound's own store, where it can be read (§4.70):
    # only the hours this device alone held the address count
    rows = unbound_rows(since, addresses)
    if rows is not None:
        windows = store.address_windows(addresses, since - 3600, now)
        devices, _ = dnslib.by_device(rows, windows)
        mine = [devices[m] for m in macs if m in devices]
        entry = {'queries': 0, 'blocked': 0, 'domains': {}, 'hours': {}, 'addresses': set()}
        for part in mine:
            entry['queries'] += part['queries']
            entry['blocked'] += part['blocked']
            entry['addresses'] |= part['addresses']
            for domain, (count, blocked, blocklist, last) in part['domains'].items():
                names = entry['domains'].setdefault(domain, [0, 0, None, 0])
                names[0] += count
                names[1] += blocked
                names[2] = names[2] or blocklist
                names[3] = max(names[3], last)
            for hour, count in part['hours'].items():
                entry['hours'][hour] = entry['hours'].get(hour, 0) + count
        listed = sorted(entry['domains'].items(), key=lambda item: (-item[1][0], item[0]))
        return {
            'enabled': True,
            'source': 'store',
            'hours': hours,
            'queries': entry['queries'],
            'blocked': entry['blocked'],
            'capped': False,
            'domains': [{'domain': d, 'count': e[0], 'blocked': e[1], 'blocklist': e[2], 'last': e[3]}
                        for d, e in listed],
            'heatmap': dnslib.heatmap_cells(entry['hours']),
            'addresses': addresses,
            'asked': len(addresses),
            'answered': len(addresses),
            'capped_pieces': 0,
        }

    pieces = dnslib.pieces(own, since, now, others, span=86400 if hours > 24 else 6 * 3600)
    answers = read_json_commands(
        [[UNBOUND_STATS, 'details', '--client', address, '--start', str(start), '--end', str(end)]
         for address, start, end in pieces],
        UNBOUND_TIMEOUT)

    report = dnslib.device_queries(answers)
    report.update({
        'enabled': True,
        'hours': hours,
        'addresses': sorted({address for address, _, _ in pieces}),
        'asked': len(pieces),
        'answered': sum(1 for answer in answers if answer is not None),
        'capped_pieces': sum(1 for answer in answers if isinstance(answer, list) and len(answer) >= dnslib.DETAILS_CAP),
        'covered': sum(end - start for _, start, end in pieces),
    })
    return report


def decode_fields(encoded):
    """
    base64url of a JSON object, as the label and configure duties receive it.

    :return: the object, or None when it is not one
    """
    padding = '=' * (-len(encoded or '') % 4)
    try:
        fields = json.loads(base64.urlsafe_b64decode((encoded or '') + padding).decode('utf-8'))
    except (ValueError, UnicodeDecodeError):
        return None
    return fields if isinstance(fields, dict) else None


def configure(encoded):
    """
    Store what the operator changed on Services: Lens: Settings (§4.58).

    All or nothing: one field out of bounds and none are written, because a form
    half-applied is a form the operator cannot reason about. The answer is JSON
    either way -- a code per field, which PHP turns into a sentence.

    A refusal still exits 0. configd answers a script that exits non-zero with
    "Execute error" and drops what it printed, so the codes would never reach
    the page; the status is in the JSON, where the page reads it.
    """
    fields = decode_fields(encoded)
    if fields is None:
        print(json.dumps({'status': 'invalid', 'errors': {'': ['not_an_object', None]}}))
        return 0

    clean, errors = settingslib.validate(fields)
    if errors:
        # the bounds travel with the refusal, so the sentence can name them
        print(json.dumps({'status': 'invalid', 'errors': errors, 'bounds': settingslib.bounds()}))
        return 0

    store = Store(DB_PATH)
    store.set_settings(clean)
    store.commit()
    print(json.dumps({'status': 'saved', 'saved': sorted(clean)}))
    return 0


def moment(store, mac, at, step):
    """One slice of one device's chart, broken into the addresses behind it."""
    macs = mac_list(mac)
    mac = macs[0]
    rows = {}

    for row in store.device_moment(macs, at, max(3600, step)):
        key = (row['address'], row['interface'])
        entry = rows.setdefault(key, {
            'address': row['address'], 'interface': row['interface'],
            'sent': 0, 'received': 0, 'octets': 0,
        })
        entry['sent' if row['direction'] == 'in' else 'received'] += row['octets']
        entry['octets'] += row['octets']

    return {
        'mac': mac,
        'at': at,
        'step': step,
        'addresses': sorted(rows.values(), key=lambda r: r['octets'], reverse=True),
    }


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

    # a sparkline per network: hourly for a day, daily beyond three
    step = 86400 if hours > DAILY_ABOVE else 3600
    start = since - since % step
    slots = list(range(start, now - now % step + step, step))
    series = {}
    for row in store.interface_timeline(start, step):
        series.setdefault(row['interface'], {})[row['at']] = row['octets']
    for interface, entry in rows.items():
        points = series.get(interface, {})
        entry['series'] = [points.get(at, 0) for at in slots]

    return {
        'hours': hours,
        'since': since,
        'step': step,
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
    macs = mac_list(mac)
    mac = macs[0]

    # Beyond three days an hourly chart is more bars than a screen has pixels,
    # so it is drawn per day instead. The step is reported, because a chart
    # whose bars silently change meaning is worse than one that says so.
    step = 86400 if hours > DAILY_ABOVE else 3600

    since = now - hours * 3600
    since -= since % step

    totals = {}
    for row in store.device_traffic(macs, since):
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
        'interfaces': store.device_interfaces_of(macs),
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

    fields = decode_fields(encoded)
    if fields is None:
        print('label fields must be a base64url JSON object', file=sys.stderr)
        return 1

    # several MACs only for the mute: a folded phone is muted as a whole (§4.63)
    macs = mac_list(mac)
    if len(macs) > 1 and set(fields) != {'muted'}:
        print('only a mute applies to several devices at once', file=sys.stderr)
        return 1

    store = Store(DB_PATH)
    now = int(time.time())
    outcomes = [store.set_label(one, fields, now) for one in macs]
    store.commit()
    print('saved' if 'saved' in outcomes else 'cleared')
    return 0


def forget(mac, dry):
    """
    Everything about one device, gone (§4.72); with --dry, only counted. The
    answer is the counts either way, so the page can show what a confirmation
    will delete and then what it did.
    """
    macs = [one for one in mac_list(mac) if one]
    if not macs:
        print(json.dumps({'error': 'forget needs --mac'}))
        return 1
    store = Store(DB_PATH)
    counts = store.forget(macs, dry=dry)
    print(json.dumps({'macs': macs, 'dry': dry, 'counts': counts}))
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
    interfaces = store.device_interfaces()
    rows = store.traffic_rows(since)
    per_mac, unattributed, worst = attribute.classify(rows, interfaces, status['first_observation'])

    return {
        'since': since,
        'hours': hours,
        'devices': per_mac,
        'unattributed': unattributed,
        'unexplained': worst,
        'first_bucket': status['first_bucket'],
        'watching_since': status['first_observation'],
        'previous': previous_week(store, since, hours, status, interfaces),
    }


def previous_week(store, since, hours, status, interfaces):
    """
    The same range one week earlier -- for a day, the same weekday, because a
    household is weekly (§4.66) -- and only when Lens watched all of it: a
    delta against half a week is a wrong number that looks like a right one.
    """
    offset = max(hours, 168) * 3600
    start, end = since - offset, since - offset + hours * 3600
    watched = status['first_observation'] is not None and status['first_bucket'] is not None \
        and status['first_observation'] <= start and status['first_bucket'] <= start
    if not watched:
        return {'covered': False, 'since': start, 'until': end}

    per_mac, _, _ = attribute.classify(store.traffic_rows(start, until=end), interfaces, status['first_observation'])
    return {
        'covered': True, 'since': start, 'until': end,
        'devices': {mac: {'octets': sum(side['octets'] for side in sides.values()),
                          'sent': sides['in']['octets']}
                    for mac, sides in per_mac.items()},
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


DAY = 86400

# core keeps FlowSourceAddrDetails for 62 days at one resolution, a day (§4.62)
DESTINATIONS_PROVIDER = 'FlowSourceAddrDetails'
DESTINATIONS_HISTORY = 61
# the first backfill spreads over runs instead of being one long one
DESTINATIONS_DAYS_PER_RUN = 7


def fetch_details(day):
    """One completed day of per-device destinations, as core's own reader returns it."""
    raw = must_read_command([
        TIMESERIES,
        '--provider', DESTINATIONS_PROVIDER,
        '--resolution', str(DAY),
        '--start_time', str(int(day)),
        '--end_time', str(int(day + DAY)),
        '--key_fields', 'if,src_addr,dst_addr,service_port,protocol,direction',
    ], COMMAND_TIMEOUT)
    try:
        return json.loads(raw) if raw.strip() else {}
    except ValueError:
        raise RuntimeError('get_timeseries.py did not answer with JSON')


def harvest_destinations(store, now):
    """
    Completed days of who each device talked to (§4.62), only when switched on.

    Resumes from the last day stored. A first run reaches back as far as core
    keeps them, but not before Lens started watching -- a day nobody observed
    cannot be joined onto a device -- and takes at most a week per run.
    """
    today = now - now % DAY
    last = store.last_bucket(DESTINATIONS_PROVIDER)
    if last is not None:
        start = last + DAY
    else:
        watching = store.first_observation() or today
        start = max(today - DESTINATIONS_HISTORY * DAY, watching - watching % DAY)

    days = []
    day = start
    while day < today and len(days) < DESTINATIONS_DAYS_PER_RUN:
        days.append(day)
        day += DAY
    if not days:
        return 'destinations up to date'

    interfaces = set(store.device_interfaces())
    written = 0
    for day in days:
        rows = parse.destinations_from_timeseries(fetch_details(day), interfaces)
        written += store.store_destinations(day, rows)
        store.commit()
    return 'destinations: %d days, %d rows' % (len(days), written)


def harvest(store, now):
    """
    Both harvests -- the hourly one always, the daily destinations when switched
    on -- and then the complete days summed once, so no page sums them (§4.69).
    """
    said = harvest_hours(store, now)
    if store.settings()['destinations_enabled']:
        said += '; ' + harvest_destinations(store, now)
    summed = store.fill_device_days(now)
    store.commit()
    if summed:
        said += '; %d days summed' % summed
    return said


def destinations(store, now, mac, days):
    """Who one device -- every MAC of it (§4.61) -- talked to over the last `days` days."""
    macs = mac_list(mac)
    days = max(1, min(int(days or 30), 366))
    today = now - now % DAY
    since = today - days * DAY
    rows = [{
        'peer': r['peer'], 'port': r['port'], 'protocol': r['protocol'],
        'sent': r['sent'], 'received': r['received'], 'days': r['days'],
    } for r in store.destinations(macs, since)]

    return {
        'macs': macs,
        'days': days,
        'since': since,
        'enabled': store.settings()['destinations_enabled'],
        'harvested_to': store.last_bucket(DESTINATIONS_PROVIDER),
        'rows': rows,
    }


def harvest_hours(store, now):
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
                 'identity', 'baseline', 'timeline', 'presence', 'profile', 'heatmap',
                 'gateways', 'internet', 'settings', 'configure', 'destinations',
                 'segments', 'moment', 'label', 'events', 'dns', 'dns-device',
                 'prune', 'purge', 'forget', 'kept'],
    )
    parser.add_argument('--mac', help='the device to label, or to forget')
    parser.add_argument('--dry', action='store_true', help='forget: count, delete nothing')
    parser.add_argument('--at', type=int, default=0, help='start of the slice to open')
    parser.add_argument('--step', type=int, default=3600, help='how long that slice is')
    parser.add_argument('--fields', help='base64url of a JSON object of label or setting fields')
    parser.add_argument('--days', type=int, default=30, help='how far back destinations and events reach')
    parser.add_argument(
        '--hours', type=int, default=DEFAULT_TRAFFIC_HOURS,
        help='how far back the traffic duty reaches (default: %d)' % DEFAULT_TRAFFIC_HOURS,
    )
    args = parser.parse_args()

    if args.duty == 'status':
        print(json.dumps(Store(DB_PATH).status()))
        return 0

    if args.duty == 'moment':
        print(json.dumps(moment(Store(DB_PATH), args.mac, args.at, args.step)))
        return 0

    if args.duty == 'segments':
        print(json.dumps(segments(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'destinations':
        print(json.dumps(destinations(Store(DB_PATH), int(time.time()), args.mac, args.days)))
        return 0

    if args.duty == 'dns':
        print(json.dumps(dns(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'dns-device':
        print(json.dumps(dns_device(Store(DB_PATH), int(time.time()), args.mac, args.hours)))
        return 0

    if args.duty == 'events':
        print(json.dumps(events(Store(DB_PATH), int(time.time()), args.days)))
        return 0

    if args.duty == 'settings':
        print(json.dumps(settingslib.describe(Store(DB_PATH).stored_settings())))
        return 0

    if args.duty == 'configure':
        return configure(args.fields)

    if args.duty == 'internet':
        print(json.dumps(internet(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'gateways':
        print(json.dumps(gateways(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'profile':
        print(json.dumps(profile(Store(DB_PATH), int(time.time()), args.mac)))
        return 0

    if args.duty == 'heatmap':
        print(json.dumps(heatmap(Store(DB_PATH), int(time.time()))))
        return 0

    if args.duty == 'presence':
        print(json.dumps(presence(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'timeline':
        print(json.dumps(timeline(Store(DB_PATH), int(time.time()), args.hours)))
        return 0

    if args.duty == 'baseline':
        print(json.dumps(baseline(Store(DB_PATH), int(time.time()))))
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

    if args.duty == 'forget':
        return forget(args.mac, args.dry)

    if args.duty == 'kept':
        store = Store(DB_PATH)
        report = store.kept()
        report['retention_days'] = store.setting_int('retention_days')
        report['destinations_on'] = bool(store.settings().get('destinations_enabled'))
        print(json.dumps(report))
        return 0

    if args.duty == 'prune':
        return run('prune', lambda store, now: '%d rows removed' % store.prune(now))

    return run(args.duty, observe if args.duty == 'observe' else harvest)


if __name__ == '__main__':
    sys.exit(main())
