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
