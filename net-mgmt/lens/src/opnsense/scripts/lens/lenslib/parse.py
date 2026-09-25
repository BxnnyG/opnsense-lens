"""
Turning what the box says into what the store keeps.

Everything here is a pure function: text or decoded JSON in, tuples out. The
collector does the reading and the writing. That split is not decoration -- the
one bug this project has shipped so far lived in wiring that could not be
reached from a test (DESIGN 4.21).
"""

import csv
import json
import io
import re

# FreeBSD `arp -an`:
#   ? (10.10.20.136) at 42:c5:38:e1:54:c7 on vtnet1_vlan20 expires in 841 seconds [vlan]
ARP_LINE = re.compile(
    r"\((?P<address>[0-9.]+)\) at (?P<mac>[0-9a-fA-F:]{11,17}) on (?P<interface>\S+)"
)

# FreeBSD `ndp -an`:
#   fe80::1%vtnet1_vlan10   bc:24:11:3e:e7:28   vtnet1_vlan10 23h59m48s S R
NDP_LINE = re.compile(
    r"^(?P<address>[0-9a-fA-F:]+)(?:%\S+)?\s+(?P<mac>[0-9a-fA-F:]{11,17})\s+(?P<interface>\S+)"
)


def normalise_mac(mac):
    """Lower case, zero padded, so two spellings of one device are one device."""
    return ':'.join(part.zfill(2).lower() for part in mac.split(':'))


def is_randomised(mac):
    """
    True when the locally administered bit is set -- a MAC the device made up.

    Two of the operator's thirteen devices are this (4.17). It does not change
    how identity is keyed; it changes how much a growing device count should
    worry us, which is why it is recorded rather than acted on.
    """
    try:
        return bool(int(mac.split(':')[0], 16) & 0x02)
    except (ValueError, IndexError):
        return False


def parse_arp(text):
    """
    :return: list of (mac, address, interface, permanent)

    `permanent` marks the firewall's own addresses. It holds one MAC across
    every VLAN, which looks exactly like extreme address churn and is not.
    """
    found = []
    for line in text.splitlines():
        hit = ARP_LINE.search(line)
        if not hit:
            continue
        found.append((
            normalise_mac(hit.group('mac')),
            hit.group('address'),
            hit.group('interface'),
            'permanent' in line,
        ))
    return found


def parse_ndp(text):
    """:return: list of (mac, address, interface)"""
    found = []
    for line in text.splitlines():
        line = line.strip()
        hit = NDP_LINE.match(line)
        if not hit or '(incomplete)' in line:
            continue
        found.append((
            normalise_mac(hit.group('mac')),
            hit.group('address'),
            hit.group('interface'),
        ))
    return found


def parse_dnsmasq_leases(text):
    """
    dnsmasq writes: <expiry> <mac> <address> <hostname> <client-id>

    :return: dict of mac to hostname, skipping the ones that name nothing
    """
    names = {}
    for line in text.splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        mac, hostname = normalise_mac(parts[1]), parts[3]
        if hostname not in ('*', ''):
            names[mac] = hostname
    return names


def parse_isc_leases(text):
    """
    ISC dhcpd writes blocks, appending rather than rewriting, so the last block
    for a MAC is the current one:

        lease 10.0.10.5 {
          hardware ethernet bc:24:11:1b:58:16;
          client-hostname "nas-01";
        }

    Found on the operator's second firewall 2026-08-30, where the page had been
    reporting "no DHCP server is running here" while isc-dhcp was handing out
    every address on the network.

    :return: dict of mac to hostname, skipping the ones that name nothing
    """
    names = {}
    mac = hostname = None

    for line in text.splitlines():
        line = line.strip()

        if line.startswith('lease '):
            mac = hostname = None
        elif line.startswith('hardware ethernet '):
            mac = normalise_mac(line[len('hardware ethernet '):].rstrip(';').strip())
        elif line.startswith('client-hostname '):
            hostname = line[len('client-hostname '):].rstrip(';').strip().strip('"')
        elif line.startswith('}'):
            if mac and hostname:
                names[mac] = hostname
            mac = hostname = None

    return names


def parse_kea_leases(text):
    """
    Kea writes a CSV with a header. Six of the operator's thirteen devices name
    themselves uselessly or not at all, so an empty hostname is not an error.

    :return: dict of mac to hostname
    """
    names = {}
    try:
        rows = csv.DictReader(io.StringIO(text))
        for row in rows:
            mac, hostname = row.get('hwaddr'), (row.get('hostname') or '').strip()
            if mac and hostname:
                names[normalise_mac(mac)] = hostname
    except (csv.Error, TypeError):
        return {}
    return names


def fold_observations(seen, open_windows, now, gap):
    """
    Decide which address observations extend a window and which open a new one.

    A device holds a SET of addresses, concurrently (4.17): the operator's admin
    PC is in MGNT and HOME at the same time, and the firewall is in eight VLANs.
    So each (mac, address, interface) has its own window and they never compete.

    A gap longer than `gap` means the device was away and came back, which is a
    new window -- and the difference matters, because attributing traffic to a
    device requires knowing the window that covers the traffic's own timestamp,
    not merely that the device once held the address.

    :param seen: iterable of (mac, address, interface) observed right now
    :param open_windows: dict of that key to the window's last_seen
    :param now: timestamp of this observation
    :param gap: seconds of absence that end a window
    :return: (extend, open) -- both lists of keys
    """
    extend, opened = [], []

    for key in sorted(set(seen)):
        last_seen = open_windows.get(key)
        if last_seen is not None and (now - last_seen) <= gap:
            extend.append(key)
        else:
            opened.append(key)

    return extend, opened


def buckets_from_timeseries(payload, complete_before, after=None):
    """
    Pull storable rows out of what `netflow aggregate fetch` returns.

    Core hands back {"<bucket>": {"<if>,<address>,<direction>": {octets, ...}}},
    padded with zero-filled slices up to the present.

    The interface is not decoration. FlowSourceAddrTotals writes each flow
    twice, and on the second write it replaces src_addr with the *destination*:

        if=if_in,  src_addr=<the device>,   direction=in
        if=if_out, src_addr=<the far end>,  direction=out

    So half the rows are not devices at all, and the only thing that tells them
    apart is which interface they arrived on. Harvesting without it, as the
    first version of this did, produces a table where a phone and a Google
    server look identical.

    Two kinds of row must not be stored, for different reasons:

    - the filler slices, because a device that sent nothing did not send zero
      bytes, it produced no record at all; and
    - the bucket covering the current hour, because it is still being written.
      Storing a partial hour and never revisiting it would freeze whatever
      happened to have accumulated by the time the collector ran.

    :param payload: decoded reply
    :param complete_before: only buckets that ended at or before this are stored
    :param after: skip buckets at or below this, already held
    :return: list of (bucket, interface, address, direction, octets, packets)
    """
    rows = []

    for raw_bucket, keys in (payload or {}).items():
        try:
            bucket = int(raw_bucket)
        except (TypeError, ValueError):
            continue

        if bucket >= complete_before:
            continue
        if after is not None and bucket <= after:
            continue

        for key, values in (keys or {}).items():
            parts = [part.strip() for part in str(key).split(',')]
            if len(parts) >= 3:
                interface, address, direction = parts[0], parts[1], parts[2]
            elif len(parts) == 2:
                # a reply without the interface: the missing field is at the front
                interface, address, direction = '', parts[0], parts[1]
            else:
                interface, address, direction = '', parts[0], ''


            if not address:
                continue

            octets = int(values.get('octets') or 0)
            packets = int(values.get('packets') or 0)

            if octets <= 0 and packets <= 0:
                continue

            rows.append((bucket, interface, address, direction, octets, packets))

    return sorted(rows)


def parse_gateway_status(text):
    """
    Core's gateway_status.php, which reports what dpinger measured:

        {"WAN_PPPOE": {"name": "WAN_PPPOE", "status": "none", "delay": "12.3 ms",
                       "stddev": "1.1 ms", "loss": "0.0 %", "monitor": "1.1.1.1"}}

    The values arrive formatted for a person, and "~" where dpinger has not
    measured anything -- a gateway with monitoring switched off. That is kept as
    None, not 0: "no reading" and "no latency" are different claims, and only
    one of them is ever true.

    :return: list of (name, delay_ms, stddev_ms, loss_pct, status, monitor)
    """
    try:
        data = json.loads(text or '{}')
    except ValueError:
        return []

    if not isinstance(data, dict):
        return []

    rows = []
    for name, gateway in sorted(data.items()):
        if not isinstance(gateway, dict):
            continue
        rows.append((
            str(name),
            _measure(gateway.get('delay')),
            _measure(gateway.get('stddev')),
            _measure(gateway.get('loss')),
            str(gateway.get('status') or ''),
            str(gateway.get('monitor') or ''),
        ))
    return rows


def _measure(value):
    """'12.3 ms' -> 12.3, '0.0 %' -> 0.0, '~' or anything unreadable -> None"""
    try:
        return float(str(value).split()[0])
    except (ValueError, IndexError):
        return None
