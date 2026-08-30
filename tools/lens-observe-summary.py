#!/usr/bin/env python3
"""
lens-observe-summary.py — read lens-observations.log and answer the identity
question (docs/BACKLOG.md §0, item #3).

    python3 lens-observe-summary.py /root/lens-observations.log

Runs on the box or off it. Read-only. It answers three things that decide how
the identity service (S1) has to be built:

  1. How many of the MACs on this network are randomised (locally administered)?
     Those are the ones that will fragment a device into many.
  2. How often does one MAC *change* address over time? That is churn, and it
     breaks naive IP-keyed attribution.
  3. How often is one address reused by different MACs? That is what makes
     attributing *historical* traffic with the *current* ARP table wrong.

Churn is reported separately from multi-homing. A device holding several
addresses *at the same time* -- an admin machine in two VLANs, a server with a
management interface, the firewall itself -- is normal and is not churn. Adding
the two together inflates the number and hides real randomisation, which is
exactly what the first version of this script did (fixed 2026-08-30).
"""
import re
import sys
from collections import defaultdict

SNAP = re.compile(r"^=== SNAPSHOT (\S+)")
# FreeBSD `arp -an`:  ? (192.168.1.10) at 00:11:22:33:44:55 on em0 ...
ARP = re.compile(r"\((?P<ip>[0-9.]+)\) at (?P<mac>[0-9a-fA-F:]{11,17}) on (?P<if>\S+)")
# `permanent` marks the firewall's own interface addresses. Its single MAC shows
# up on every VLAN, which looks exactly like extreme address churn and is not.
PERMANENT = "permanent"
# FreeBSD `ndp -an`:  fe80::1%em0   00:11:22:33:44:55   em0  ...
NDP = re.compile(r"^(?P<ip>[0-9a-fA-F:]+)(?:%\S+)?\s+(?P<mac>[0-9a-fA-F:]{11,17})\s+(?P<if>\S+)")


def is_randomised(mac):
    """Locally administered bit set in the first octet -> a randomised MAC."""
    try:
        return bool(int(mac.split(":")[0], 16) & 0x02)
    except ValueError:
        return False


def norm(mac):
    return ":".join(p.zfill(2).lower() for p in mac.split(":"))


def main(path):
    snapshots = []
    section = None
    mac_ips = defaultdict(set)
    mac_v4 = defaultdict(set)
    mac_v6 = defaultdict(set)
    ip_macs = defaultdict(set)
    mac_seen = defaultdict(list)
    mac_if = defaultdict(set)
    own_macs = set()
    # (mac, snapshot index) -> addresses seen together in that one snapshot.
    # Lets multi-homing be told apart from an address that changed over time.
    concurrent = defaultdict(lambda: defaultdict(set))

    with open(path, errors="replace") as fh:
        for line in fh:
            m = SNAP.match(line)
            if m:
                snapshots.append(m.group(1))
                section = None
                continue
            if line.startswith("--- "):
                section = line[4:].strip().split()[0]
                continue
            if not snapshots:
                continue
            hit = None
            if section == "arp":
                hit = ARP.search(line)
            elif section == "ndp":
                hit = NDP.match(line.strip())
            if not hit:
                continue
            mac, ip = norm(hit.group("mac")), hit.group("ip")
            if PERMANENT in line:
                own_macs.add(mac)
            if mac.startswith("ff:ff") or ip.startswith("ff"):
                continue
            mac_ips[mac].add(ip)
            if ":" in ip:
                mac_v6[mac].add(ip)
            else:
                mac_v4[mac].add(ip)
                concurrent[mac][len(snapshots) - 1].add(ip)
            ip_macs[ip].add(mac)
            mac_seen[mac].append(len(snapshots) - 1)
            mac_if[mac].add(hit.group("if"))

    if not snapshots:
        print("No snapshots found. Is this a lens-observations.log?")
        return 1

    total = len(snapshots)
    for m in own_macs:            # the firewall is not a client
        mac_ips.pop(m, None)
        mac_v4.pop(m, None)
        mac_v6.pop(m, None)
    for ip in list(ip_macs):
        ip_macs[ip] -= own_macs
        if not ip_macs[ip]:
            del ip_macs[ip]
    rnd = {m for m in mac_ips if is_randomised(m)}

    print(f"Observation window : {snapshots[0]}  ->  {snapshots[-1]}")
    print(f"Snapshots          : {total}")
    print(f"Own MACs excluded  : {len(own_macs)} (the firewall's own interfaces)")
    print()
    print(f"Distinct MACs      : {len(mac_ips)}")
    print(f"  randomised       : {len(rnd)}  <- each of these may be one device wearing many faces")
    print(f"  stable (burned-in): {len(mac_ips) - len(rnd)}")
    print(f"Distinct addresses : {len(ip_macs)}")
    print()

    def widest(m):
        """Most addresses this MAC held simultaneously, in any one snapshot."""
        return max((len(v) for v in concurrent[m].values()), default=0)

    homed = {m: widest(m) for m in mac_v4 if widest(m) > 1}
    print(f"MACs multi-homed (several IPv4 AT ONCE): {len(homed)}")
    print("  (normal: an admin PC in two VLANs, a server with a mgmt interface)")
    for m, n in sorted(homed.items(), key=lambda kv: -kv[1])[:10]:
        tag = " [randomised]" if m in rnd else ""
        print(f"  {m}{tag}  ->  {n} addresses at once")

    print()
    churn = {m: ips for m, ips in mac_v4.items() if len(ips) > widest(m)}
    print(f"MACs whose IPv4 CHANGED over time     : {len(churn)}")
    print("  (this is churn, and the only half of it that threatens attribution)")
    for m, ips in sorted(churn.items(), key=lambda kv: -len(kv[1]))[:10]:
        tag = " [randomised]" if m in rnd else ""
        print(f"  {m}{tag}  ->  {len(ips)} addresses over time, {widest(m)} at once")

    churn6 = {m: ips for m, ips in mac_v6.items() if len(ips) > 2}
    print(f"MACs with more than 2 IPv6 addresses  : {len(churn6)}")
    print("  (privacy extensions rotating; link-local + one stable is normal)")

    print()
    reuse = {ip: macs for ip, macs in ip_macs.items() if len(macs) > 1}
    print(f"Addresses used by more than one MAC  : {len(reuse)}")
    print("  (every one of these is a case where attributing history by IP is wrong)")
    for ip, macs in sorted(reuse.items(), key=lambda kv: -len(kv[1]))[:10]:
        print(f"  {ip}  <-  {len(macs)} MACs")

    print()
    if total >= 20:
        brief = [m for m, s in mac_seen.items() if len(set(s)) <= total // 20]
        print(f"MACs present in <=5% of snapshots     : {len(brief)}")
        print("  (a large number here, mostly randomised, is the fragmentation signature)")
        print(f"  of which randomised: {len([m for m in brief if m in rnd])}")
    else:
        print(f"MACs present in <=5% of snapshots     : not enough samples yet")
        print(f"  ({total} snapshots; this needs 20+, i.e. about two hours)")

    print()
    print("READ THIS AS: if 'randomised' and 'MACs present in <=5%' are both")
    print("large, keying identity on MAC alone will produce a device list that")
    print("grows forever. If both are small, MAC is a sound key and S1 is easy.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else "/root/lens-observations.log"))
