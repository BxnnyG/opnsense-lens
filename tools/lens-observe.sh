#!/bin/sh
#
# lens-observe.sh — record who is on this network, over time.
#
# Read-only. Appends plain text to a log file and touches nothing else.
#
# This exists to answer the one question that cannot be answered in the
# abstract (docs/BACKLOG.md §0, item #3): how badly does MAC randomisation and
# address rotation fragment device identity on a *real* network? Design of the
# identity service (S1) waits on the answer.
#
# Run once:      sh /root/lens-observe.sh
# Run overnight: daemon -f -p /var/run/lens-observe.pid sh /root/lens-observe.sh --loop
# Stop it:       pkill -f lens-observe
#
# `daemon` is used rather than a shell loop because OPNsense's root shell is
# csh, which does not understand `2>&1` — and because it detaches properly and
# leaves a pidfile. Cost: one arp/ndp/lease read every 5 minutes, roughly
# 1-3 MB per day.

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH

LOG=${LENS_OBSERVE_LOG:-/root/lens-observations.log}
MAX_MB=${LENS_OBSERVE_MAX_MB:-200}
INTERVAL=${LENS_OBSERVE_INTERVAL:-300}

# --loop: keep taking snapshots until killed or the ceiling is reached.
if [ "$1" = "--loop" ]; then
    while :; do
        sh "$0" || exit 1
        sleep "$INTERVAL"
    done
fi

# Refuse to fill the disk. A reporting tool that takes the firewall down has
# negative value (docs/DESIGN.md §4.8).
if [ -f "$LOG" ]; then
    size_mb=$(( $(wc -c < "$LOG") / 1048576 ))
    if [ "$size_mb" -ge "$MAX_MB" ]; then
        echo "lens-observe: $LOG is ${size_mb}MB, at the ${MAX_MB}MB ceiling — stopping." >&2
        exit 1
    fi
fi

{
    echo "=== SNAPSHOT $(date '+%Y-%m-%dT%H:%M:%S%z')"

    echo "--- arp"
    arp -an 2>/dev/null

    echo "--- ndp"
    ndp -an 2>/dev/null

    echo "--- leases"
    for f in /var/db/kea/kea-leases4.csv /var/db/kea/kea-leases6.csv \
             /var/etc/dnsmasq.leases /var/lib/dnsmasq/dnsmasq.leases \
             /var/dhcpd/var/db/dhcpd.leases; do
        [ -r "$f" ] && { echo "--- lease-file $f"; cat "$f"; }
    done

    echo "--- end"
} >> "$LOG"
