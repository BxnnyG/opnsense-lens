"""
What happened, derived from what the store already keeps (§4.63).

No event table: every event here is a fact the store holds with its time, and a
second copy could only disagree with the first. Each function takes rows and
returns events; the collector's `events` duty feeds them, PHP words them.
"""

from . import baseline as baselib

DAY = 86400

# one lossy five-minute sample is the chart's business; three in a row is news
GATEWAY_RUN = 3

# a gap this long between two samples means nobody measured, not that it held
GATEWAY_GAP = 900

# what dpinger reports, and what Lens calls it (LineQuality.php says the same)
GATEWAY_DOWN = ('down', 'force_down')
GATEWAY_FINE = ('none', '')


def unusual_days(rows, first_day, today, needs_days, factor, floor):
    """
    Each day in [first_day, today] judged the way the live verdict judged it that
    evening: against the needs_days + 1 days before it, never with hindsight.

    :param rows: (mac, day, octets, sent), day a whole-day number
    :return: list of {'mac', 'day', 'octets', 'usual', 'times', 'partial'}, oldest first
    """
    rows = [(mac, int(day), octets, sent) for mac, day, octets, sent in rows]
    found = []
    for day in range(int(first_day), int(today) + 1):
        window = [row for row in rows if day - needs_days - 1 <= row[1] <= day]
        report = baselib.assess_both(window, day, needs_days=needs_days, factor=factor, floor=floor)
        for entry in report['unusual']:
            found.append({
                'mac': entry['mac'],
                'day': day * DAY,
                'octets': entry['today'],
                'usual': entry['usual'],
                'times': entry['times'],
                'direction': entry['direction'],
                'sent': entry.get('sent'),
                'sent_usual': entry.get('sent_usual'),
                'sent_times': entry.get('sent_times'),
                'partial': day == today,
            })
    return found


def gateway_runs(samples, now):
    """
    Runs of a gateway not being fine, at least GATEWAY_RUN samples long.

    A run ends at the first fine sample -- the line was still bad in between --
    or, where the samples stop, one sample after the last bad one.

    :param samples: (name, at, status, loss) ordered by name, then time
    :return: list of {'name', 'from', 'to', 'state', 'samples', 'loss', 'ongoing'}
    """
    runs = []
    current = None
    previous = None

    def close(end, ongoing=False):
        if current and current['samples'] >= GATEWAY_RUN:
            current['to'] = end
            current['ongoing'] = ongoing
            runs.append(current)

    def stopped():
        """the samples ran out while it was bad: still bad now, or unknown since"""
        still = previous >= now - GATEWAY_GAP
        close(now if still else previous + 300, still)

    for name, at, status, loss in samples:
        at = int(at)
        status = (status or '').lower()
        if current and name != current['name']:
            stopped()
            current = None
        elif current and at - previous > GATEWAY_GAP:
            close(previous + 300)
            current = None
        if status in GATEWAY_FINE:
            if current:
                close(at)
            current = None
        else:
            if current is None:
                current = {'name': name, 'from': at, 'state': 'degraded', 'samples': 0, 'loss': 0.0}
            current['samples'] += 1
            current['loss'] = max(current['loss'], float(loss or 0))
            if status in GATEWAY_DOWN:
                current['state'] = 'down'
        previous = at

    if current:
        stopped()

    runs.sort(key=lambda run: run['from'])
    return runs


def new_devices(rows, watching_since, since):
    """
    Devices that appeared in the range, and only once Lens has watched for two
    days (§4.34): before that, everything is new.

    :param rows: (mac, first_seen)
    :return: (list of {'mac', 'at'}, the moment from which "new" is said)
    """
    if watching_since is None:
        return [], None
    new_from = int(watching_since) + 2 * DAY
    return [{'mac': mac, 'at': int(at)} for mac, at in rows
            if int(at) >= max(since, new_from)], new_from
