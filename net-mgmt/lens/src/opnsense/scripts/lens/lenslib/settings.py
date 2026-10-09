"""
What an operator may change, and the bounds on each (DESIGN §4.58).

One owner for the rules. The collector refuses anything outside them and says
which field and why, as a code; PHP turns the code into a sentence, and the
browser enforces nothing it could be talked out of.

Stored as text in the store's `setting` table, which stage 4 created for the
first three of these. The bounds are enforced where a value is written through
the page. On read, a number that parses is taken as stored -- a ceiling set to 0
from a shell is a deliberate way to stop writing, and the tests use it -- and
only a value that cannot be read at all falls back to its default, rather than
stopping the observation that asked for it.
"""

import ipaddress
import re

from lenslib import baseline

MB = 1024 * 1024

# Three operators' anycast resolvers, the names people already know (§4.57).
DEFAULT_TARGETS = (
    ('Quad9', '9.9.9.9'),
    ('Cloudflare', '1.1.1.1'),
    ('Google', '8.8.8.8'),
)

# key: (kind, stored default, low, high). For targets the bounds are a count.
SPEC = {
    # how long observations and harvested traffic are kept
    'retention_days': ('int', '365', 7, 3650),
    # above this the collector stops writing rather than fill /var on a firewall
    'disk_ceiling_mb': ('int', '500', 50, 20000),
    # Seconds of absence that end an address window, and so what "gone" means.
    # Observe runs every 300; below twice that, one late run splits a visit.
    'observation_gap': ('int', '900', 600, 3600),
    # the only traffic Lens sends itself (§4.57)
    'probe_enabled': ('flag', '1', None, None),
    'probe_targets': ('targets', ','.join('%s=%s' % t for t in DEFAULT_TARGETS), 1, 3),
    # dpinger's readings stored for the history chart; the live card reads core
    'gateway_samples': ('flag', '1', None, None),
    # a phone's rotating private MACs shown as one device (§4.61)
    'fold_randomised': ('flag', '1', None, None),
    # who each device talks to, per day: personal, so opt-in (rule 9, §4.62)
    'destinations_enabled': ('flag', '0', None, None),
    # what each device looked up, read live from Unbound and never stored (§4.64)
    'dns_per_device': ('flag', '1', None, None),
    # the three guards on "unusual" (§4.50)
    'baseline_days': ('int', str(baseline.NEEDS_DAYS), 7, 90),
    'baseline_factor': ('float', repr(baseline.FACTOR), 1.5, 20.0),
    'baseline_floor_mb': ('int', str(baseline.FLOOR // MB), 1, 100000),
    # Networks a pause never touches (§4.83), as interface devices. Empty means
    # the network the click comes from; the page offers only real interfaces.
    'pause_protected': ('interfaces', '', 0, 64),
    # How Lens's pages write times and dates (operator, 2026-10-09: "Uhrzeit,
    # in welchem Format"). "auto" is the browser's own language. For a choice
    # the third field is the options, not a bound.
    'clock': ('choice', 'auto', ('auto', '24', '12'), None),
    'date_order': ('choice', 'auto', ('auto', 'dmy', 'mdy', 'ymd'), None),
}

TARGET_NAME = re.compile(r'^[A-Za-z0-9 ._-]{1,24}$')
INTERFACE = re.compile(r'^[A-Za-z0-9_.:-]{1,32}$')


def defaults():
    """:return: every key's default, as stored text"""
    return {key: spec[1] for key, spec in SPEC.items()}


def load(stored):
    """
    :param stored: dict of key to stored text, possibly incomplete
    :return: dict of key to typed value; unreadable or missing is the default
    """
    values = {}
    for key, (kind, default, low, high) in SPEC.items():
        if kind in ('int', 'float'):
            low, high = None, None
        value, error = _parse(kind, stored.get(key, default), low, high)
        if error is not None:
            value, _ = _parse(kind, default, low, high)
        values[key] = value
    return values


def validate(fields):
    """
    :param fields: dict of key to what the page sent
    :return: (clean, errors) -- clean is key to stored text, errors is key to
             [code, row or None]. Nothing is clean if anything is wrong: a form
             half-applied is a form the operator cannot reason about.
    """
    clean, errors = {}, {}

    if not isinstance(fields, dict):
        return {}, {'': ['not_an_object', None]}

    for key, value in fields.items():
        if key not in SPEC:
            errors[key] = ['unknown', None]
            continue

        kind, _, low, high = SPEC[key]
        parsed, error = _parse(kind, value, low, high)
        if error is not None:
            errors[key] = error
            continue

        clean[key] = _store(kind, parsed)

    return ({}, errors) if errors else (clean, {})


def bounds():
    """:return: key to [low, high]; a count for targets, absent for switches and choices"""
    return {key: [spec[2], spec[3]] for key, spec in SPEC.items()
            if spec[2] is not None and spec[0] != 'choice'}


def choices():
    """:return: key to its options, for the settings page's selects"""
    return {key: list(spec[2]) for key, spec in SPEC.items() if spec[0] == 'choice'}


def describe(stored):
    """What the settings page needs: values, defaults and bounds, typed."""
    return {
        'values': _plain(load(stored)),
        'defaults': _plain(load({})),
        'bounds': bounds(),
        'choices': choices(),
    }


# ------------------------------------------------------------------ internal

def _parse(kind, value, low, high):
    """:return: (typed value, None) or (None, [code, row])"""
    if kind == 'int':
        return _number(value, low, high, int)
    if kind == 'float':
        return _number(value, low, high, float)
    if kind == 'flag':
        return _flag(value)
    if kind == 'interfaces':
        return _interfaces(value, high)
    if kind == 'choice':
        text = str(value).strip().lower()
        return (text, None) if text in low else (None, ['not_a_choice', None])
    return _targets(value, low, high)


def _number(value, low, high, cast):
    if isinstance(value, bool):
        return None, ['not_a_number', None]
    try:
        number = cast(float(str(value).strip()))
    except (TypeError, ValueError, OverflowError):
        return None, ['not_a_number', None]
    if number != number:                                        # NaN
        return None, ['not_a_number', None]
    if cast is int and float(str(value).strip()) != number:
        return None, ['not_whole', None]
    if low is not None and number < low:
        return None, ['too_small', None]
    if high is not None and number > high:
        return None, ['too_large', None]
    return number, None


def _flag(value):
    text = str(value).strip().lower()
    if text in ('1', 'true', 'on', 'yes'):
        return True, None
    if text in ('0', 'false', 'off', 'no', ''):
        return False, None
    return None, ['not_a_flag', None]


def _targets(value, low, high):
    """
    Accepts the stored form, "Name=1.2.3.4,Other=5.6.7.8", or what the page
    sends: a list of {name, address} or [name, address]. Blank rows are not
    targets; the page always offers three rows.
    """
    if isinstance(value, str):
        rows = [part.split('=', 1) if '=' in part else [part, '']
                for part in value.split(',') if part.strip()]
    elif isinstance(value, (list, tuple)):
        rows = []
        for row in value:
            if isinstance(row, dict):
                rows.append([row.get('name', ''), row.get('address', '')])
            elif isinstance(row, (list, tuple)) and len(row) == 2:
                rows.append(list(row))
            else:
                return None, ['bad_row', len(rows) + 1]
    else:
        return None, ['bad_row', None]

    targets, seen = [], set()
    for index, (name, address) in enumerate(rows, start=1):
        name, address = str(name or '').strip(), str(address or '').strip()
        if not name and not address:
            continue
        if not TARGET_NAME.match(name):
            return None, ['bad_name', index]
        try:
            parsed = ipaddress.ip_address(address)
        except ValueError:
            return None, ['bad_address', index]
        if parsed.version != 4:
            # ping -t is a deadline in seconds for IPv4 on FreeBSD; for IPv6 it
            # has not been seen on the box, so it is not offered (§4.58)
            return None, ['ipv6', index]
        if not parsed.is_global:
            return None, ['not_public', index]
        if str(parsed) in seen:
            return None, ['duplicate', index]
        seen.add(str(parsed))
        targets.append((name, str(parsed)))

    if len(targets) < low:
        return None, ['too_few', None]
    if len(targets) > high:
        return None, ['too_many', None]
    return targets, None


def _interfaces(value, high):
    """Accepts the stored form, "vlan0.10,igb1", or a list of names; none is fine."""
    if isinstance(value, str):
        names = [part.strip() for part in value.split(',')]
    elif isinstance(value, (list, tuple)):
        names = [str(part).strip() for part in value]
    else:
        return None, ['bad_row', None]

    out = []
    for index, name in enumerate([name for name in names if name], start=1):
        if not INTERFACE.match(name):
            return None, ['bad_name', index]
        if name not in out:
            out.append(name)
    if high is not None and len(out) > high:
        return None, ['too_many', None]
    return out, None


def _store(kind, value):
    if kind == 'flag':
        return '1' if value else '0'
    if kind == 'interfaces':
        return ','.join(value)
    if kind == 'targets':
        return ','.join('%s=%s' % target for target in value)
    if kind == 'float':
        return repr(float(value))
    if kind == 'choice':
        return value
    return str(int(value))


def _plain(values):
    """JSON-friendly: targets as objects rather than tuples."""
    out = dict(values)
    out['probe_targets'] = [{'name': name, 'address': address}
                            for name, address in values['probe_targets']]
    return out
