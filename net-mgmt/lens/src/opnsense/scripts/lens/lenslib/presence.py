"""
When each device was here.

The address windows the collector has kept since stage 4 *are* this answer: a
window opens when a device is first seen on an address and closes after it has
been gone for longer than the observation gap. Drawn per device across a day,
they are the "who's home" view that no page in OPNsense has (§4.52).

Two things have to happen before they can be drawn, and both are here so they
can be tested without a browser:

  merge   a device holding two addresses has two overlapping windows. Drawn
          separately it would look present twice; merged it is present once.
  clip    a window that opened last week and is still open today starts, for a
          chart of today, at midnight -- not before the left edge of the chart.
"""


def spans(windows, since, now):
    """
    :param windows: (mac, first_seen, last_seen)
    :param since: left edge of the chart
    :param now: right edge of the chart
    :return: dict of mac to a sorted list of non-overlapping [start, end]
    """
    per_mac = {}

    for mac, first_seen, last_seen in windows:
        start = max(int(first_seen), since)
        end = min(int(last_seen), now)
        if end < start:
            continue
        per_mac.setdefault(mac, []).append([start, end])

    return {mac: _merge(intervals) for mac, intervals in per_mac.items()}


# a silence longer than this is a device that went away, not a quiet one
BRIDGE_MAX = 86400
HOUR = 3600


def bridges(windows, traffic, max_gap=BRIDGE_MAX):
    """
    The hours a quiet device was there although the ARP table had let it go
    (stage 56): a gap between two windows of the same device on the same
    address, bridged hour by hour where that address moved traffic, clipped to
    the gap -- and only when no other device held the address in between.

    :param windows: (mac, address, first_seen, last_seen)
    :param traffic: address -> iterable of hour starts in which it moved traffic
    :return: list of (mac, start, end)
    """
    by_address = {}
    for mac, address, first_seen, last_seen in windows:
        by_address.setdefault(address, []).append((mac, int(first_seen), int(last_seen)))

    found = []
    for address, held in by_address.items():
        hours = sorted(set(int(h) for h in traffic.get(address, ())))
        if not hours:
            continue
        for mac in {entry[0] for entry in held}:
            own = sorted((first, last) for who, first, last in held if who == mac)
            others = [(first, last) for who, first, last in held if who != mac]
            for (_, gap_start), (gap_end, _) in zip(own, own[1:]):
                if gap_end <= gap_start or gap_end - gap_start > max_gap:
                    continue
                if any(first < gap_end and last > gap_start for first, last in others):
                    continue
                for hour in hours:
                    start, end = max(hour, gap_start), min(hour + HOUR, gap_end)
                    if start < end:
                        found.append((mac, start, end))
    return found


def seconds(intervals):
    """:return: how long the device was present, in total"""
    return sum(end - start for start, end in intervals)


def _merge(intervals):
    """
    Overlapping or touching intervals become one. Touching counts: a device
    seen at 14:00 on one address and at 14:00 on another was present
    continuously, not twice.
    """
    merged = []

    for start, end in sorted(intervals):
        if merged and start <= merged[-1][1]:
            merged[-1][1] = max(merged[-1][1], end)
        else:
            merged.append([start, end])

    return merged
