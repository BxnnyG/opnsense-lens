"""
What the network looks up, joined onto identity (§4.64).

Core's Unbound keeps seven days of queries per client address in DuckDB and
answers through its own stats.py. Lens stores none of it: it reads on demand and
puts each query onto the device that held the address at the time -- the same
rule the traffic join follows, at the grain Unbound offers (ten minutes).

The shapes below were read from stats.py at stable/26.7, not captured from a box
(plan stage 36 §2). Every reader here is defensive for that reason.
"""

SLOT = 600

# stats.py details answers at most this many rows per call (its --limit default,
# which the configd action does not pass and Lens does not raise)
DETAILS_CAP = 500


def _int(value):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return 0


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
            entry = domains.setdefault(row['domain'].rstrip('.'), {
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
