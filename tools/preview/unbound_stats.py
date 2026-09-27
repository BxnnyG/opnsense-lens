#!/usr/bin/env python3
"""
A stand-in for core's /usr/local/opnsense/scripts/unbound/stats.py, answering in
the shapes that script prints at stable/26.7 (plan stage 36 §2) for the seeded
household. Deterministic: the same minute gives the same answer.

    unbound_stats.py totals --max N
    unbound_stats.py rolling --interval 600 --timeperiod 24 --clients
    unbound_stats.py details --client IP --start S --end E
"""

import argparse
import json
import random
import time

# who asks, and for what -- the seeded devices' current addresses
ASKERS = {
    '10.10.20.11': (220, ['apple.com', 'icloud.com', 'github.com', 'slack.com', 'zoom.us', 'google.com']),
    '10.10.20.23': (140, ['apple.com', 'icloud.com', 'instagram.com', 'whatsapp.net', 'spotify.com']),
    '10.10.20.24': (90, ['google.com', 'instagram.com', 'tiktokv.com', 'app-measurement.com']),
    '10.10.20.30': (300, ['netflix.com', 'nflxvideo.net', 'lgtvsdp.com', 'lgappstv.com', 'doubleclick.net',
                          'googleads.g.doubleclick.net']),
    '10.10.20.31': (260, ['github.com', 'archlinux.org', 'cachyos.org', 'google.com', 'discord.com']),
    '10.10.20.62': (120, ['google.com', 'googleapis.com', 'whatsapp.net', 'app-measurement.com']),
    '10.10.21.20': (40, ['iot-eu.camera-cloud.example', 'pool.ntp.org']),
    '10.10.21.30': (60, ['home-assistant.io', 'pool.ntp.org', 'github.com']),
    '10.10.30.10': (30, ['backblazeb2.com', 'ubuntu.com', 'pool.ntp.org']),
}
BLOCKED = {'doubleclick.net', 'googleads.g.doubleclick.net', 'app-measurement.com', 'tiktokv.com'}


def queries(address, start, end, rng):
    rate, domains = ASKERS[address]
    out = []
    at = end
    while at > start and len(out) < 500:
        at -= int(rng.expovariate(rate / 3600.0)) + 1
        domain = rng.choice(domains)
        prefix = rng.choice(['', 'www.', 'api.', 'cdn.'])
        out.append((at, prefix + domain))
    return out


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command')
    parser.add_argument('--max', type=int, default=10)
    parser.add_argument('--interval', type=int, default=600)
    parser.add_argument('--timeperiod', type=int, default=24)
    parser.add_argument('--clients', action='store_true')
    parser.add_argument('--client')
    parser.add_argument('--start', type=int)
    parser.add_argument('--end', type=int)
    parser.add_argument('--limit', type=int, default=500)
    args = parser.parse_args()

    now = int(time.time()) // 60 * 60
    rng = random.Random(now // 3600)

    if args.command == 'totals':
        counts, blocked = {}, {}
        for address, (rate, domains) in ASKERS.items():
            for domain in domains:
                share = rate * 24 * 7 // len(domains)
                (blocked if domain in BLOCKED else counts)[domain] = \
                    (blocked if domain in BLOCKED else counts).get(domain, 0) + share
        passed, stopped = sum(counts.values()), sum(blocked.values())
        total = passed + stopped
        print(json.dumps({
            'total': total, 'blocklist_size': 184233, 'passed': passed,
            'resolved': {'total': int(passed * 0.31), 'pcnt': '%.2f' % (passed * 0.31 / total * 100)},
            'blocked': {'total': stopped, 'pcnt': '%.2f' % (stopped / total * 100)},
            'local': {'total': int(total * 0.04), 'pcnt': '4.00'},
            'start_time': now - 7 * 86400 + 1800,
            'top': {domain: {'total': count, 'pcnt': '%.2f' % (count / passed * 100)}
                    for domain, count in sorted(counts.items(), key=lambda item: -item[1])[:args.max]},
            'top_blocked': {domain: {'total': count, 'pcnt': '%.2f' % (count / stopped * 100),
                                     'blocklist': 'ads', 'latest_policy_uuid': None}
                            for domain, count in sorted(blocked.items(), key=lambda item: -item[1])[:args.max]},
        }))
        return

    if args.command == 'rolling' and args.clients:
        slots = {}
        start = now - now % args.interval
        for n in range(args.timeperiod * 3600 // args.interval):
            slot = start - n * args.interval
            clients = {}
            for address, (rate, _) in ASKERS.items():
                count = int(rng.gauss(rate * args.interval / 3600.0, 3))
                if count > 0:
                    clients[address] = {'count': count, 'hostname': ''}
            # DuckDB's epoch() is a double; the real keys may carry a fraction
            slots['%.1f' % slot] = dict(sorted(clients.items(), key=lambda item: -item[1]['count'])[:10])
        print(json.dumps(slots))
        return

    if args.command == 'details' and args.client in ASKERS:
        rows = [{
            'uuid': None, 'time': at, 'client': args.client, 'family': 'IPv4', 'type': 'A',
            'domain': domain + '.', 'action': 'Block' if domain.split('.', 1)[-1] in BLOCKED or domain in BLOCKED
            else 'Pass', 'source': 'Cache', 'blocklist': 'ads' if domain in BLOCKED else None,
            'rcode': 'NOERROR', 'resolve_time_ms': 0, 'dnssec_status': 'Unchecked', 'ttl': 300,
        } for at, domain in queries(args.client, args.start or now - 86400, args.end or now, rng)]
        print(json.dumps(rows[:args.limit]))
        return

    print(json.dumps([] if args.command == 'details' else {}))


if __name__ == '__main__':
    main()
