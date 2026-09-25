"""
When the internet was there, and when it was not.

A round of probes is "down" when **every** target lost every packet. One
resolver timing out is that resolver's problem; all three at once, on three
different operators' anycast networks, is the line (§4.57).

An outage is a run of consecutive down rounds. It starts at the first down
round and ends at the first round that got an answer again -- not at the last
down one -- because the line was still out in between, and a five-minute
outage reported as zero minutes long would be a lie of rounding.
"""


def assess(rounds, since, now, slots):
    """
    :param rounds: (at, best_loss) per observation run, oldest first
    :param since: left edge
    :param now: right edge
    :param slots: how many pieces to cut the strip into
    :return: {'up_pct', 'outages': [[start, end]], 'strip': [state...], 'rounds': n}
    """
    rounds = [(int(at), loss) for at, loss in rounds if loss is not None]

    outages = []
    start = None
    for at, loss in rounds:
        if loss >= 100.0:
            if start is None:
                start = at
        elif start is not None:
            outages.append([start, at])
            start = None
    if start is not None:
        outages.append([start, now])

    up = sum(1 for _, loss in rounds if loss < 100.0)

    return {
        'rounds': len(rounds),
        'up_pct': round(up / len(rounds) * 100, 2) if rounds else None,
        'outages': outages,
        'strip': _strip(rounds, since, now, slots),
    }


def _strip(rounds, since, now, slots):
    """
    One state per slice: 'up', 'down', 'partial' (some rounds down), or
    'none' where no round ran -- which is drawn as a gap, not as green, because
    "we did not look" is not "it was fine".
    """
    width = max(1, (now - since) / float(slots))
    buckets = [[] for _ in range(slots)]

    for at, loss in rounds:
        index = int((at - since) / width)
        if 0 <= index < slots:
            buckets[index].append(loss >= 100.0)

    strip = []
    for bucket in buckets:
        if not bucket:
            strip.append('none')
        elif all(bucket):
            strip.append('down')
        elif any(bucket):
            strip.append('partial')
        else:
            strip.append('up')
    return strip
