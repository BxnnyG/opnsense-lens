#!/bin/sh
#
# lens-preflight.sh — what data can Lens actually see on this box?
#
# Read-only. Changes nothing, starts nothing, stops nothing.
# Run on the OPNsense box as root:
#
#     sh /root/lens-preflight.sh
#
# It prints the report AND writes it to /root/lens-preflight.txt by itself.
# Do not add a shell pipeline: OPNsense's root shell is csh, which does not
# understand $(...) or 2>&1, and the command would fail before the script ran.
# Override the output path with LENS_OUT if you want to keep several runs.
#
# This script is the hand-run version of what stage 2 (S3, Preflight) will do
# inside the plugin. Keep the two in step: anything learned here belongs in
# docs/DESIGN.md §1.

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH

# Write our own report file, so the caller never has to type shell-specific
# redirection. The root shell here is csh; assuming sh syntax is how the first
# run of this script failed (2026-08-30).
OUT=${LENS_OUT:-/root/lens-preflight.txt}
if [ -z "$LENS_TEEING" ]; then
    LENS_TEEING=1
    export LENS_TEEING
    sh "$0" "$@" 2>&1 | tee "$OUT"
    echo
    echo "Report written to $OUT"
    exit 0
fi

sec() { echo; echo "================ $* ================"; }
try() { echo "\$ $*"; "$@" 2>&1 | sed 's/^/  /'; }

echo "lens-preflight  $(date '+%Y-%m-%d %H:%M:%S %z')  on $(hostname)"

sec "1. Box"
try opnsense-version -v
try uname -a
echo "\$ sysctl hw.model hw.ncpu hw.physmem"
sysctl hw.model hw.ncpu hw.physmem 2>&1 | sed 's/^/  /'
try df -h /var /conf /

sec "2. NetFlow configuration (//OPNsense/Netflow in config.xml)"
php << 'PHPEOF'
<?php
$c = @simplexml_load_file('/conf/config.xml');
if ($c === false) { echo "  cannot read /conf/config.xml\n"; exit(0); }
$n = $c->xpath('//OPNsense/Netflow');
if (!$n) {
    echo "  NO <Netflow> node at all — NetFlow has never been configured.\n";
    exit(0);
}
echo "  " . str_replace("\n", "\n  ", trim($n[0]->asXML())) . "\n\n";
$cap = (string)($n[0]->capture->interfaces ?? '');
$col = (string)($n[0]->collect->enable ?? '0');
echo "  VERDICT capture interfaces : " . ($cap === '' ? "NONE — nothing is being captured" : $cap) . "\n";
echo "  VERDICT local collection   : " . ($col === '1' ? "ON" : "OFF — no history is being kept") . "\n";
PHPEOF

sec "3. NetFlow services"
# configd addresses a dotted action name with spaces: [collect.status] is
# reached as `netflow collect status`. Core does the same -- see
# configdRun('interface list ifconfig') for [list.ifconfig]. Verified 2026-08-30
# after the dotted form returned "Action not allowed or missing".
try configctl netflow status
try configctl netflow collect status
try configctl netflow aggregate status
try configctl netflow cache stats

sec "4. NetFlow data on disk"
echo "  raw flow log (/var/log/flowd.log):"
ls -lh /var/log/flowd.log* 2>&1 | sed 's/^/    /'
echo "  aggregate databases (/var/netflow):"
ls -lh /var/netflow 2>&1 | sed 's/^/    /'
echo "  total size:"
du -sh /var/netflow 2>&1 | sed 's/^/    /'

sec "5. NetFlow aggregation metadata (how far back does it go?)"
try configctl netflow aggregate metadata text
echo
echo "  Retention is fixed in core and differs per aggregate (verified 2026-08-30):"
echo "    FlowInterfaceTotals     30s->1d   300s->7d   3600s->31d  86400s->365d"
echo "    FlowSourceAddrTotals              300s->1h   3600s->1d   86400s->365d"
echo "    FlowSourceAddrDetails             300s->1h   3600s->1d   86400s->365d"
echo "    FlowDstPortTotals                 300s->1h   3600s->1d   86400s->365d"
echo "  i.e. PER-CLIENT data older than 24 hours exists only as DAILY totals."

sec "6. DNS and DHCP services actually running"
# Config says 'enabled'; that is not the same as 'running'. Checking only the
# config is how this script reported Unbound reporting as ON on a box that
# resolves with dnsmasq (2026-08-30).
for p in unbound dnsmasq named kea-dhcp4 kea-dhcp6 dhcpd; do
    printf '  %-12s : ' "$p"
    if pgrep -q "$p" 2>/dev/null; then echo "RUNNING"; else echo "not running"; fi
done
echo
try configctl unbound status
try configctl dnsmasq status

sec "6b. Unbound DNS reporting (configuration)"
php << 'PHPEOF'
<?php
$c = @simplexml_load_file('/conf/config.xml');
if ($c === false) { echo "  cannot read /conf/config.xml\n"; exit(0); }
$n = $c->xpath('//OPNsense/unboundplus/general');
if (!$n) { echo "  NO <unboundplus/general> node — Unbound not configured here.\n"; exit(0); }
$en  = (string)($n[0]->enabled ?? '0');
$st  = (string)($n[0]->stats ?? '0');
echo "  VERDICT unbound enabled  : " . ($en === '1' ? "ON" : "OFF") . "\n";
echo "  VERDICT query reporting  : " . ($st === '1' ? "ON" : "OFF — Reporting > Unbound DNS will be empty") . "\n";
PHPEOF
echo "  statistics database:"
ls -lh /var/unbound/data/unbound.duckdb 2>&1 | sed 's/^/    /'
# Configured is not running, and running is not serving (§4.18). Only the age of
# the data says whether this source is real: on 2026-08-30 this file had not
# been written for ten hours on a box that resolves all day -- with dnsmasq.
if [ -f /var/unbound/data/unbound.duckdb ]; then
    age=$(( $(date +%s) - $(stat -f %m /var/unbound/data/unbound.duckdb) ))
    echo "  last written     : ${age} seconds ago"
    if [ "$age" -gt 3600 ]; then
        echo "  VERDICT freshness: STALE — unbound is not answering queries here,"
        echo "                     whatever the configuration and the process say."
    else
        echo "  VERDICT freshness: fresh — unbound is really serving queries"
    fi
fi

sec "7. Which DHCP server is in use?"
for s in kea-dhcp4 kea-dhcp6 dnsmasq dhcpd; do
    printf '  %-12s : ' "$s"
    if pgrep -q "$s" 2>/dev/null; then echo "RUNNING"; else echo "not running"; fi
done
echo "  lease files found:"
# /var/db/dnsmasq.leases verified against core's get_dnsmasq_leases.py, 2026-08-30.
ls -lh /var/db/dnsmasq.leases /var/db/kea/*.csv \
       /var/dhcpd/var/db/dhcpd.leases 2>/dev/null | sed 's/^/    /'
echo "    (nothing listed above = none of the known paths exist)"
echo
try configctl dnsmasq list leases

sec "8. Identity sources available right now"
echo "  ARP entries : $(arp -an 2>/dev/null | wc -l | tr -d ' ')"
echo "  NDP entries : $(ndp -an 2>/dev/null | tail -n +2 | wc -l | tr -d ' ')"
echo
echo "  ARP table:"
arp -an 2>&1 | sed 's/^/    /'

sec "9. Interfaces (candidates for NetFlow capture)"
echo "\$ ifconfig -l"
ifconfig -l 2>&1 | sed 's/^/  /'
php << 'PHPEOF'
<?php
$c = @simplexml_load_file('/conf/config.xml');
if ($c === false) { exit(0); }
echo "\n  configured interfaces (name => device, description):\n";
foreach ($c->interfaces->children() as $name => $iface) {
    printf("    %-10s => %-10s %s\n", $name,
        (string)$iface->if, (string)$iface->descr);
}
PHPEOF

sec "Done"
echo "Send the whole file back. Nothing was changed."
