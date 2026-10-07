"""
What the network looks up, joined onto identity (§4.64).

Core's Unbound keeps seven days of queries per client address in DuckDB and
answers through its own stats.py. Lens stores none of it: it reads on demand and
puts each query onto the device that held the address at the time -- the same
rule the traffic join follows, at the grain Unbound offers (ten minutes).

The shapes below were read from stats.py at stable/26.7, not captured from a box
(plan stage 36 §2). Every reader here is defensive for that reason.
"""

import time

SLOT = 600

# stats.py details answers at most this many rows per call (its --limit default,
# which the configd action does not pass and Lens does not raise)
DETAILS_CAP = 500


def _int(value):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return 0


def name(domain):
    """
    A name as a page shows it: without the trailing dot -- except the root
    itself, which Unbound asks when it starts and which would otherwise be an
    empty line with a number beside it (router-01, 2026-10-07).
    """
    return str(domain or '').rstrip('.') or '.'


def _pct(value):
    """'12.34', or the integer 0 when there was nothing to divide"""
    try:
        return round(float(value), 1)
    except (TypeError, ValueError):
        return 0.0


def totals(payload):
    """
    :param payload: `stats.py totals` decoded
    :return: the counters and both top lists, typed; None when it is not that shape
    """
    if not isinstance(payload, dict) or 'total' not in payload:
        return None

    def part(key):
        entry = payload.get(key)
        return {'total': _int(entry.get('total')), 'pct': _pct(entry.get('pcnt'))} \
            if isinstance(entry, dict) else {'total': 0, 'pct': 0.0}

    top = [{'domain': domain, 'count': _int(entry.get('total')), 'pct': _pct(entry.get('pcnt'))}
           for domain, entry in (payload.get('top') or {}).items() if isinstance(entry, dict)]
    blocked = [{'domain': domain, 'count': _int(entry.get('total')), 'pct': _pct(entry.get('pcnt')),
                'blocklist': entry.get('blocklist') or None}
               for domain, entry in (payload.get('top_blocked') or {}).items() if isinstance(entry, dict)]

    return {
        'total': _int(payload.get('total')),
        'passed': _int(payload.get('passed')),
        'blocklist_size': _int(payload.get('blocklist_size')),
        'resolved': part('resolved'),
        'blocked': part('blocked'),
        'local': part('local'),
        'start_time': _int(payload.get('start_time')) or None,
        'top': sorted(top, key=lambda entry: -entry['count']),
        'top_blocked': sorted(blocked, key=lambda entry: -entry['count']),
    }


def client_slots(payload):
    """
    :param payload: `stats.py rolling --clients` decoded: slot start to client
                    address to {'count', 'hostname'}
    :return: list of (slot, address, count)
    """
    slots = []
    if not isinstance(payload, dict):
        return slots
    for slot, clients in payload.items():
        if not isinstance(clients, dict):
            continue
        for address, entry in clients.items():
            count = _int(entry.get('count')) if isinstance(entry, dict) else 0
            if count > 0 and address:
                slots.append((_int(slot), str(address), count))
    return slots


def attribute(slots, windows):
    """
    Each slot's queries onto the device that held the address in that slot.

    :param slots: (slot, address, count)
    :param windows: (mac, address, first_seen, last_seen)
    :return: (dict mac to {'queries', 'addresses'}, dict address to unattributed count)
    """
    by_address = {}
    for mac, address, first_seen, last_seen in windows:
        by_address.setdefault(address, []).append((mac, int(first_seen), int(last_seen)))

    devices, unplaced = {}, {}
    for slot, address, count in slots:
        holders = {mac for mac, first_seen, last_seen in by_address.get(address, [])
                   if first_seen < slot + SLOT and last_seen >= slot}
        if len(holders) == 1:
            mac = holders.pop()
            entry = devices.setdefault(mac, {'queries': 0, 'addresses': set()})
            entry['queries'] += count
            entry['addresses'].add(address)
        else:
            unplaced[address] = unplaced.get(address, 0) + count

    for entry in devices.values():
        entry['addresses'] = sorted(entry['addresses'])
    return devices, unplaced


def pieces(windows, since, until, others=(), keep=8, span=86400):
    """
    The stretches of time each address was this device's, clipped to the range,
    newest first, at most `keep`: one `details` call each.

    A phone that leaves and comes back on the same lease has a window per visit.
    Two windows of one address are one stretch when nobody else held the address
    in between -- whatever was asked from it then was asked by this device or by
    nobody -- so one call covers both. A gap someone else filled stays a gap.

    :param windows: (address, first_seen, last_seen) of this device
    :param others: (address, first_seen, last_seen) of every other device on those addresses
    :param span: longer stretches are cut into pieces this long. stats.py answers
                 the newest 500 queries of a call and no more, so one call for a
                 week would show its last few hours; a call per day spreads what
                 is seen across the range
    :return: list of (address, start, end)
    """
    taken = {}
    for address, first_seen, last_seen in others:
        taken.setdefault(address, []).append((int(first_seen), int(last_seen)))

    merged = []
    for address, first_seen, last_seen in sorted(windows, key=lambda window: (window[0], int(window[1]))):
        first_seen, last_seen = int(first_seen), int(last_seen)
        if merged and merged[-1][0] == address and not any(
                start < first_seen and end > merged[-1][2] for start, end in taken.get(address, [])):
            merged[-1] = (address, merged[-1][1], max(merged[-1][2], last_seen))
        else:
            merged.append((address, first_seen, last_seen))

    clipped = []
    for address, first_seen, last_seen in merged:
        start, end = max(int(first_seen), since), min(int(last_seen) + SLOT, until)
        while start < end:
            clipped.append((address, max(start, end - span), end))
            end -= span
    clipped.sort(key=lambda piece: -piece[2])
    return clipped[:keep]


def device_queries(answers):
    """
    :param answers: one `stats.py details` answer (a list of rows) per piece
    :return: {'queries', 'blocked', 'capped', 'domains': [...], 'first', 'last'}
    """
    domains = {}
    queries = blocked = 0
    capped = False
    first = last = None

    for rows in answers:
        if not isinstance(rows, list):
            continue
        capped = capped or len(rows) >= DETAILS_CAP
        for row in rows:
            if not isinstance(row, dict) or not row.get('domain'):
                continue
            at = _int(row.get('time'))
            action = str(row.get('action') or '')
            entry = domains.setdefault(name(row['domain']), {
                'count': 0, 'blocked': 0, 'last': 0, 'blocklist': None, 'types': set()})
            entry['count'] += 1
            entry['last'] = max(entry['last'], at)
            if row.get('type'):
                entry['types'].add(str(row['type']))
            if action == 'Block':
                entry['blocked'] += 1
                entry['blocklist'] = row.get('blocklist') or entry['blocklist']
                blocked += 1
            queries += 1
            first = at if first is None else min(first, at)
            last = at if last is None else max(last, at)

    listed = [{'domain': domain, 'count': entry['count'], 'blocked': entry['blocked'],
               'last': entry['last'], 'blocklist': entry['blocklist'],
               'types': sorted(entry['types'])}
              for domain, entry in domains.items()]
    listed.sort(key=lambda entry: (-entry['count'], entry['domain']))

    return {'queries': queries, 'blocked': blocked, 'capped': capped,
            'domains': listed, 'first': first, 'last': last}


# ------------------------------------------------------------ from the store
#
# Since stage 44 (§4.70) the counts come from Unbound's own DuckDB store, read
# through core's duckdb_helper, when it can be read: every question, not the
# newest 500 of a request. Rows arrive already summed per client, name and hour:
# (client, domain, hour, questions, blocked, blocklist, resolved, local).

HOUR = 3600


def holders(windows):
    """:return: a function (client, hour) -> the one MAC that held it, or None"""
    by_address = {}
    for mac, address, first_seen, last_seen in windows:
        by_address.setdefault(address, []).append((mac, int(first_seen), int(last_seen)))

    def held(client, hour):
        found = {mac for mac, first_seen, last_seen in by_address.get(client, [])
                 if first_seen < hour + HOUR and last_seen >= hour}
        return found.pop() if len(found) == 1 else None
    return held


def _tally(entry, domain, hour, count, blocked, blocklist):
    entry['queries'] += count
    entry['blocked'] += blocked
    names = entry['domains'].setdefault(domain, [0, 0, None, 0])
    names[0] += count
    names[1] += blocked
    names[2] = names[2] or (blocklist if blocked else None)
    names[3] = max(names[3], hour)
    entry['hours'][hour] = entry['hours'].get(hour, 0) + count


def by_device(rows, windows):
    """
    Every question on the device that held its client address in that hour --
    the traffic join's rule, at the hour Unbound's rows are summed to.

    :return: (devices, unplaced): mac or client address -> {'queries', 'blocked',
             'domains': {name: [questions, blocked, blocklist, last hour]},
             'hours': {hour: questions}, 'addresses': set}
    """
    held = holders(windows)
    devices, unplaced = {}, {}
    for client, domain, hour, count, blocked, blocklist, *_ in rows:
        hour, count, blocked = int(hour), int(count), int(blocked or 0)
        domain = name(domain)
        mac = held(client, hour)
        pool, key = (devices, mac) if mac else (unplaced, client)
        entry = pool.setdefault(key, {'queries': 0, 'blocked': 0, 'domains': {}, 'hours': {}, 'addresses': set()})
        entry['addresses'].add(client)
        _tally(entry, domain, hour, count, blocked, blocklist)
    return devices, unplaced


def totals_from_rows(rows, blocklist_size=0):
    """The overview's figures and top lists, from the same rows (stats.py's shape, typed)."""
    total = blocked = resolved = local = 0
    names, first = {}, None
    for client, domain, hour, count, stopped, blocklist, answered_up, answered_here in rows:
        count, stopped = int(count), int(stopped or 0)
        total += count
        blocked += stopped
        resolved += int(answered_up or 0)
        local += int(answered_here or 0)
        first = int(hour) if first is None else min(first, int(hour))
        entry = names.setdefault(name(domain), [0, 0, None])
        entry[0] += count
        entry[1] += stopped
        entry[2] = entry[2] or (blocklist if stopped else None)

    def pct(part, whole):
        return round(part / whole * 100, 1) if whole else 0.0

    passed = total - blocked
    top = sorted(((d, e[0] - e[1]) for d, e in names.items() if e[0] > e[1]), key=lambda x: -x[1])[:15]
    stopped = sorted(((d, e[1], e[2]) for d, e in names.items() if e[1]), key=lambda x: -x[1])[:15]
    return {
        'total': total, 'passed': passed, 'blocklist_size': int(blocklist_size or 0),
        'resolved': {'total': resolved, 'pct': pct(resolved, total)},
        'blocked': {'total': blocked, 'pct': pct(blocked, total)},
        'local': {'total': local, 'pct': pct(local, total)},
        'start_time': first,
        'top': [{'domain': d, 'count': n, 'pct': pct(n, passed)} for d, n in top],
        'top_blocked': [{'domain': d, 'count': n, 'pct': pct(n, blocked), 'blocklist': b} for d, n, b in stopped],
    }


def device_summary(entry, keep=10):
    """One device's line on the overview: its total and its most-asked names."""
    names = sorted(entry['domains'].items(), key=lambda item: (-item[1][0], item[0]))
    return {
        'queries': entry['queries'],
        'blocked': entry['blocked'],
        'addresses': sorted(entry['addresses']),
        'names': len(names),
        'domains': [{'domain': d, 'count': e[0], 'blocked': e[1], 'blocklist': e[2]} for d, e in names[:keep]],
    }


def names_by_askers(devices, unplaced, keep=25, askers=3):
    """
    The other way round: each name, how often, how often blocked, and who asked.

    :return: list of {'domain', 'count', 'blocked', 'blocklist', 'askers': [{'mac', 'address', 'count'}]}
    """
    names = {}
    for pool, placed in ((devices, True), (unplaced, False)):
        for key, entry in pool.items():
            for domain, (count, blocked, blocklist, _) in entry['domains'].items():
                row = names.setdefault(domain, {'domain': domain, 'count': 0, 'blocked': 0,
                                                'blocklist': None, 'askers': []})
                row['count'] += count
                row['blocked'] += blocked
                row['blocklist'] = row['blocklist'] or blocklist
                row['askers'].append({'mac': key if placed else None,
                                      'address': None if placed else key, 'count': count})
    listed = sorted(names.values(), key=lambda row: (-row['count'], row['domain']))[:keep]
    for row in listed:
        row['askers'] = sorted(row['askers'], key=lambda asker: -asker['count'])[:askers]
    return listed


def heatmap_cells(hours):
    """{hour: questions} -> [dow (0 = Sunday), hour, questions], in the box's own time zone."""
    cells = {}
    for at, count in hours.items():
        local = time.localtime(int(at))
        key = ((local.tm_wday + 1) % 7, local.tm_hour)
        cells[key] = cells.get(key, 0) + count
    return [[dow, hour, count] for (dow, hour), count in sorted(cells.items())]
