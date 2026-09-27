#!/usr/bin/env python3
"""
A believable store for looking at Lens without a router.

Thirty days of a household shaped like the operator's own network (ROADMAP,
operations notes): VLANs for home, IoT, guests, servers and management behind a
PPPoE line, a hypervisor with a herd of guests, phones with randomised MACs that
come and go, one device having an unusual day, and one short outage.

It writes through the collector's own Store and in the collector's own shapes,
so every page reads exactly the code path it reads on a box. It is seeded, so
two runs draw the same pictures and a before/after comparison means something.

    python3 tools/preview/seed.py /tmp/lens-preview.sqlite [--days 30] [--now EPOCH]
"""

import argparse
import math
import os
import random
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'net-mgmt', 'lens', 'src', 'opnsense', 'scripts', 'lens'))

from lenslib import parse                                       # noqa: E402
from lenslib.store import Store                                 # noqa: E402

HOUR = 3600
DAY = 86400
MB = 1024 * 1024

WAN = 'pppoe0'

# interface, the operator's name for it -- mirrored in fixtures/config.xml
SEGMENTS = {
    'vtnet1_vlan10': 'MGNT',
    'vtnet1_vlan20': 'HOME',
    'vtnet1_vlan21': 'IOT',
    'vtnet1_vlan22': 'GUEST',
    'vtnet1_vlan30': 'SERVER',
}

# mac, interface, address, hostname, source, profile, daily MB, upload share
DEVICES = [
    ('00:0d:b9:4a:10:01', 'vtnet1_vlan10', '10.10.10.1', None, None, 'firewall', 0, 0.5),
    ('24:5a:4c:11:22:33', 'vtnet1_vlan10', '10.10.10.2', None, None, 'always', 40, 0.3),
    ('f4:92:bf:aa:bb:01', 'vtnet1_vlan10', '10.10.10.5', 'UAP-AC-Pro', 'dnsmasq', 'always', 25, 0.4),
    ('3c:22:fb:10:20:30', 'vtnet1_vlan20', '10.10.20.11', 'MacBook-Pro', 'dnsmasq', 'workday', 2600, 0.15),
    ('da:a1:19:5e:00:01', 'vtnet1_vlan20', '10.10.20.23', 'iPhone', 'dnsmasq', 'evening', 1400, 0.1),
    ('ae:44:12:9b:7c:02', 'vtnet1_vlan20', '10.10.20.24', 'Galaxy-A55', 'dnsmasq', 'evening', 900, 0.08),
    ('50:c7:bf:01:02:03', 'vtnet1_vlan20', '10.10.20.30', 'LG-webOS-TV', 'dnsmasq', 'tv', 6200, 0.01),
    ('38:f9:d3:77:66:55', 'vtnet1_vlan20', '10.10.20.31', 'bxy-cachyos-x8664', 'dnsmasq', 'workday', 9100, 0.2),
    ('d8:3a:dd:12:34:56', 'vtnet1_vlan20', '10.10.20.40', None, None, 'always', 60, 0.5),
    ('a4:cf:12:00:00:01', 'vtnet1_vlan21', '10.10.21.11', 'tasmota-kitchen', 'dnsmasq', 'always', 3, 0.5),
    ('a4:cf:12:00:00:02', 'vtnet1_vlan21', '10.10.21.12', 'tasmota-garden', 'dnsmasq', 'always', 3, 0.5),
    ('a4:cf:12:00:00:03', 'vtnet1_vlan21', '10.10.21.13', 'tasmota-office', 'dnsmasq', 'always', 3, 0.5),
    ('2c:aa:8e:40:50:60', 'vtnet1_vlan21', '10.10.21.20', 'camera-garden', 'dnsmasq', 'always', 800, 0.92),
    ('b8:27:eb:05:06:07', 'vtnet1_vlan21', '10.10.21.30', 'homeassistant', 'dnsmasq', 'always', 180, 0.4),
    ('fa:16:3e:ab:cd:ef', 'vtnet1_vlan22', '10.10.22.50', 'A55-von-Karin', 'dnsmasq', 'guest', 700, 0.1),
    ('00:11:32:aa:bb:cc', 'vtnet1_vlan30', '10.10.30.10', 'nas', 'dnsmasq', 'always', 3200, 0.55),
]

# One phone that rotated its private MAC twice (§4.61): three MACs, one name,
# never two at once. The operator saw exactly this "three or four times".
ROTATING = {
    'e6:11:22:33:44:01': (0, 11),
    'e6:11:22:33:44:02': (11, 21),
    'e6:11:22:33:44:03': (21, 99),
}
for index, mac in enumerate(ROTATING):
    DEVICES.append((mac, 'vtnet1_vlan20', '10.10.20.%d' % (60 + index), 'Pixel-8', 'dnsmasq',
                    'evening', 1100, 0.12))

# a hypervisor's guests: one vendor, one segment, a herd (§4.33)
for n in range(1, 9):
    DEVICES.append(('bc:24:11:1b:58:%02x' % n, 'vtnet1_vlan30', '10.10.30.%d' % (100 + n),
                    None, None, 'always', random.Random(n).choice([30, 80, 150, 400, 900]), 0.5))

LABELS = {
    '3c:22:fb:10:20:30': {'name': "Anna's MacBook", 'kind': 'laptop', 'tags': 'anna'},
    'da:a1:19:5e:00:01': {'name': "Anna's iPhone", 'kind': 'phone', 'tags': 'anna'},
    'ae:44:12:9b:7c:02': {'name': "Ben's phone", 'kind': 'phone', 'tags': 'ben,kids'},
    '38:f9:d3:77:66:55': {'name': 'Workstation', 'tags': 'benny'},
    '00:11:32:aa:bb:cc': {'name': 'NAS', 'kind': 'server', 'tags': 'infra', 'note': 'Backups at 03:00'},
    '2c:aa:8e:40:50:60': {'name': 'Garden camera', 'tags': 'iot'},
    'bc:24:11:1b:58:01': {'name': 'mail', 'tags': 'infra'},
    'bc:24:11:1b:58:02': {'name': 'grafana', 'tags': 'infra'},
}


# who a device talks to, by day (§4.62): peer, port, protocol, share of its day
DESTINATIONS = {
    '2c:aa:8e:40:50:60': [('52.28.113.9', 8883, 6, 0.86), ('162.159.200.1', 123, 17, 0.001),
                          ('34.107.221.82', 443, 6, 0.1)],
    '00:11:32:aa:bb:cc': [('185.199.108.20', 443, 6, 0.62), ('10.10.20.31', 445, 6, 0.2),
                          ('91.189.91.39', 80, 6, 0.08), ('9.9.9.9', 853, 6, 0.004)],
    '50:c7:bf:01:02:03': [('198.38.120.14', 443, 6, 0.58), ('142.250.185.78', 443, 6, 0.3),
                          ('23.205.12.8', 443, 6, 0.06), ('3.120.44.9', 443, 6, 0.02)],
    'e6:11:22:33:44:01': [('17.253.53.207', 443, 6, 0.3), ('157.240.20.35', 443, 6, 0.25),
                          ('142.250.185.78', 443, 17, 0.2)],
    'e6:11:22:33:44:02': [('17.253.53.207', 443, 6, 0.3), ('157.240.20.35', 443, 6, 0.25),
                          ('142.250.185.78', 443, 17, 0.2)],
    'e6:11:22:33:44:03': [('17.253.53.207', 443, 6, 0.3), ('157.240.20.35', 443, 6, 0.25),
                          ('142.250.185.78', 443, 17, 0.2), ('149.154.167.91', 443, 6, 0.08)],
}


def diurnal(hour, profile):
    """How busy a device is at this hour of the day, 0..1."""
    if profile in ('always', 'firewall'):
        return 0.55 + 0.45 * math.sin((hour - 9) / 24 * 2 * math.pi) ** 2
    if profile == 'tv':
        return 1.0 if 19 <= hour <= 23 else 0.02
    if profile == 'workday':
        return 1.0 if 8 <= hour <= 18 else (0.3 if 19 <= hour <= 23 else 0.03)
    return 1.0 if hour >= 17 or hour <= 1 else 0.15


def present(day, hour, profile, weekday, rng):
    """Whether a device of this kind is on the network in this hour."""
    if profile in ('always', 'firewall', 'tv'):
        return True
    if profile == 'workday':
        return (weekday < 5 and 7 <= hour <= 23) or (weekday >= 5 and 10 <= hour <= 22)
    if profile == 'guest':
        return day % 7 in (5, 6) and 14 <= hour <= 22
    # phones: home in the evening and overnight, out during the day on weekdays
    if weekday < 5 and 8 <= hour <= 17:
        return rng.random() < 0.08
    return rng.random() < 0.97


def far_end(rng):
    return '%d.%d.%d.%d' % (rng.choice([142, 151, 172, 185, 20, 52, 104]),
                            rng.randrange(256), rng.randrange(256), rng.randrange(1, 255))


def seed(path, days, now):
    if os.path.exists(path):
        os.remove(path)
    store = Store(path)
    rng = random.Random(7)

    now -= now % 300
    start = now - days * DAY
    start -= start % DAY
    today = now // DAY

    buckets = []
    for mac, interface, address, hostname, source, profile, daily_mb, up_share in DEVICES:
        first = start + (rng.randrange(0, 6 * HOUR) if profile != 'guest' else 5 * DAY)
        if mac in ROTATING:
            first = start + ROTATING[mac][0] * DAY
        store.see_device(mac, first, randomised=parse.is_randomised(mac),
                         is_local=profile == 'firewall', hostname=hostname, source=source)

        window = None
        hour_at = start
        while hour_at < now - now % HOUR:
            day = hour_at // DAY
            hour = (hour_at % DAY) // HOUR
            weekday = (day + 3) % 7                             # 1970-01-01 was a Thursday
            here = hour_at >= first and present(day - start // DAY, hour, profile, weekday, rng)
            if mac in ROTATING:
                active_from, active_to = ROTATING[mac]
                here = here and active_from <= day - start // DAY < active_to

            if here:
                seen_from = hour_at + (rng.randrange(0, 1800) if window is None else 0)
                if window is None:
                    window = [seen_from, hour_at + HOUR - 60]
                else:
                    window[1] = hour_at + HOUR - 60
            elif window is not None:
                store.db.execute(
                    "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
                    " VALUES (?, ?, ?, ?, ?)", (mac, address, interface, window[0], window[1]))
                window = None

            if here and daily_mb and hour_at < now - now % HOUR:
                share = diurnal(hour, profile) / 12.0
                octets = int(daily_mb * MB * share * rng.uniform(0.6, 1.4))
                up = up_share
                if mac == '00:11:32:aa:bb:cc' and day == today and hour <= 4:
                    octets *= 30                                # tonight's backup went somewhere
                    up = 0.97
                if mac == '00:11:32:aa:bb:cc' and hour == 3:
                    octets = int(octets * 3)
                sent, received = int(octets * up), int(octets * (1 - up))
                buckets.append((hour_at, interface, address, 'in', sent, sent // 1200 + 1))
                buckets.append((hour_at, interface, address, 'out', received, received // 1200 + 1))
                peer = far_end(rng)
                buckets.append((hour_at, WAN, peer, 'out', sent, sent // 1200 + 1))
                buckets.append((hour_at, WAN, peer, 'in', received, received // 1200 + 1))
            hour_at += HOUR

        last = window[1] if window else None
        if window is not None:
            store.db.execute(
                "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
                " VALUES (?, ?, ?, ?, ?)", (mac, address, interface, window[0], now))
            last = now
        store.db.execute("UPDATE device SET last_seen = ? WHERE mac = ?",
                         (last or first + HOUR, mac))

    # a lease that moved: the MacBook also held a second address a week ago
    store.db.execute(
        "INSERT INTO address_observation (mac, address, interface, first_seen, last_seen)"
        " VALUES (?, ?, ?, ?, ?)",
        ('3c:22:fb:10:20:30', 'fd00:10:20::11', 'vtnet1_vlan20', now - 8 * DAY, now - 6 * DAY))

    # a transit network nobody on it was seen holding (§4.31)
    for hour_at in range(now - 2 * DAY - (now % HOUR), now - now % HOUR, HOUR):
        buckets.append((hour_at, 'wt0', '100.80.%d.%d' % (hour_at % 7, hour_at % 200), 'in',
                        rng.randrange(20, 90) * MB, 1000))

    store.store_buckets('FlowSourceAddrTotals', buckets)

    # three weeks of who talked to whom, whole days only, as the harvest keeps them
    places = {mac: (interface, address, daily_mb, up_share)
              for mac, interface, address, _h, _s, _p, daily_mb, up_share in DEVICES}
    for day in range(today - 21, today):
        for mac, peers in DESTINATIONS.items():
            if mac in ROTATING and not ROTATING[mac][0] <= day - start // DAY < ROTATING[mac][1]:
                continue
            interface, address, daily_mb, up_share = places[mac]
            rows, rest = [], 1.0
            for peer, port, protocol, share in peers:
                octets = int(daily_mb * MB * share * rng.uniform(0.7, 1.3))
                rest -= share
                rows.append((day * DAY, interface, address, peer, port, protocol, 'in',
                             int(octets * up_share), octets // 1400 + 1))
                rows.append((day * DAY, interface, address, peer, port, protocol, 'out',
                             int(octets * (1 - up_share)), octets // 1400 + 1))
            if rest > 0.01:
                octets = int(daily_mb * MB * rest)
                rows.append((day * DAY, interface, address, '*', 0, 0, 'in', int(octets * up_share), 1))
                rows.append((day * DAY, interface, address, '*', 0, 0, 'out', int(octets * (1 - up_share)), 1))
            store.store_destinations(day * DAY, rows)
    store.set_settings({'destinations_enabled': '1'})

    for mac, fields in LABELS.items():
        store.set_label(mac, fields, now - 20 * DAY)

    # the line and the internet, every five minutes
    at = now - days * DAY
    outage = (now - 2 * DAY + 3 * HOUR, now - 2 * DAY + 3 * HOUR + 25 * 60)
    gateway_rows, probe_rows = [], []
    while at <= now:
        evening = 19 <= (at % DAY) // HOUR <= 22
        delay = rng.gauss(14 if not evening else 19, 2.5)
        loss = 0.0 if rng.random() > 0.01 else rng.choice([5.0, 10.0, 20.0])
        down = outage[0] <= at < outage[1]
        gateway_rows.append((at, 'WAN_PPPOE', None if down else round(delay, 1),
                             None if down else round(abs(rng.gauss(1.5, 0.6)), 1),
                             100.0 if down else loss, 'down' if down else 'none'))
        if at >= now - 8 * DAY:
            for target, base in (('Quad9', 11.0), ('Cloudflare', 9.5), ('Google', 12.5)):
                rtt = None if down else round(rng.gauss(base + (4 if evening else 0), 1.2), 1)
                probe_rows.append((at, target, rtt, None if down else 0.6, 100.0 if down else 0.0))
        at += 300
    store.db.executemany(
        "INSERT INTO gateway_sample (at, name, delay, stddev, loss, status) VALUES (?, ?, ?, ?, ?, ?)",
        gateway_rows)
    store.db.executemany(
        "INSERT INTO probe_sample (at, target, rtt, stddev, loss) VALUES (?, ?, ?, ?, ?)",
        probe_rows)

    store.log_run('observe', now - 120, True, 2140,
                  '%d devices, %d addresses, 0 new windows; 1 gateways sampled; 3 of 3 probes answered'
                  % (len(DEVICES), len(DEVICES)))
    store.log_run('harvest', now - 900, True, 612, '64 buckets offered, 64 new, in 1 chunks')
    store.log_run('prune', now - 10 * HOUR, True, 48, '0 rows removed')
    store.commit()
    return len(DEVICES), len(buckets)


def main():
    parser = argparse.ArgumentParser(description='seed a Lens store for the preview')
    parser.add_argument('path')
    parser.add_argument('--days', type=int, default=30)
    parser.add_argument('--now', type=int, default=int(time.time()))
    args = parser.parse_args()

    devices, rows = seed(args.path, args.days, args.now)
    print('%s: %d devices, %d traffic rows over %d days' % (args.path, devices, rows, args.days))


if __name__ == '__main__':
    main()
