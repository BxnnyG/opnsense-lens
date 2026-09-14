"""
What is usual for a device, and what is not.

This is the first thing Lens does that is a *judgement* rather than a record,
and the bar it has to clear is higher for that reason: the page has promised
since stage 4 that a baseline needs about three weeks "before it is allowed to
call anything unusual", and a verdict that cries wolf in week one would spend
the credibility every other number on the page has been earning.

So three guards, and all three have to pass before anything is called unusual:

  enough days    twenty-one complete days of history for that device. Fewer and
                 the answer is "still learning", said plainly.
  a multiple     today is at least FACTOR times the device's median day. A
                 median rather than a mean because one backup night would drag
                 a mean up and hide everything afterwards.
  a floor        today is also at least FLOOR bytes above that median. Without
                 it, 3 KB against a 1 KB median is "three times normal", which
                 is true, useless, and the fastest way to teach someone to
                 ignore the column.
"""

NEEDS_DAYS = 21
FACTOR = 4.0
FLOOR = 100 * 1024 * 1024


def fold(rows):
    """
    :param rows: (mac, day, octets), day being a whole-day number
    :return: dict of mac to {day: octets}
    """
    days = {}
    for mac, day, octets in rows:
        days.setdefault(mac, {})[int(day)] = octets
    return days


def assess(rows, today, needs_days=NEEDS_DAYS, factor=FACTOR, floor=FLOOR):
    """
    :param rows: (mac, day, octets)
    :param today: the current whole-day number; it is never part of a baseline
    :return: {'unusual': [...], 'learning': [...], 'days': n}
    """
    unusual, learning = [], []
    longest = 0

    for mac, days in sorted(fold(rows).items()):
        # today is still being written, so it cannot be part of what is usual
        past = sorted(octets for day, octets in days.items() if day < today)
        longest = max(longest, len(past))

        if len(past) < needs_days:
            learning.append({'mac': mac, 'days': len(past)})
            continue

        usual = _median(past)
        now = days.get(today, 0)

        if now >= usual * factor and now - usual >= floor:
            unusual.append({
                'mac': mac,
                'today': now,
                'usual': usual,
                'times': round(now / usual, 1) if usual else None,
            })

    unusual.sort(key=lambda entry: entry['today'], reverse=True)

    return {
        'unusual': unusual,
        'learning': len(learning),
        'days': longest,
        'needs_days': needs_days,
    }


def _median(values):
    """:param values: sorted, non-empty"""
    middle = len(values) // 2

    if len(values) % 2:
        return values[middle]

    return (values[middle - 1] + values[middle]) / 2
