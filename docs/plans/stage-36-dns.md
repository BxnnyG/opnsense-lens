# Stage 36 — What the network looks up (Plan)

> Systems: S12, S5 · Roadmap stages 11 and 20 · Planned 2026-09-27 · Decision: §4.64

## 1. The problem from the user's point of view

"What is the TV phoning home to" and "which device asks for the tracker the
blocklist caught" are the questions Zenarmor, Pi-hole and Firewalla are bought
for. Core's Reporting: Unbound DNS answers them per IP address; nothing answers
them per *device*, and an IP address on a DHCP network is not one.

## 2. What core has (read from source, stable/26.7 at `e7c800f` — not yet run on a box)

§1.6 made the DNS view wait for one real `qstats` output. The operator asked
for it to be built; what stands in for the capture is the producing code itself,
read in full: `src/opnsense/scripts/unbound/stats.py` and `logger.py`.

- **Store:** `/var/unbound/data/unbound.duckdb`, table `query` (uuid, time,
  client, family, type, domain, action 0 pass/1 block/2 drop, source 0
  recursion/1 local/2 local-data/3 cache, blocklist, rcode, resolve_time_ms,
  dnssec_status, ttl). The logger deletes everything older than **seven days**,
  hourly. Only written when *Services: Unbound DNS: Statistics → Enabled*.
- `stats.py totals --max N` → `{total, blocklist_size, passed, resolved{total,
  pcnt}, blocked{…}, local{…}, start_time, top{domain: {total, pcnt}},
  top_blocked{domain: {total, pcnt, blocklist, latest_policy_uuid}}}` over the
  whole store. `pcnt` is a string, or the integer 0.
- `stats.py rolling --interval 600 --timeperiod 24 --clients` →
  `{slot_start: {client_ip: {count, hostname}}}`, the **top ten** clients of
  each ten-minute slot. Slot keys are DuckDB epochs and may arrive as floats.
- `stats.py details --client IP --start S --end E` → the client's queries in
  the window, newest first, **at most 500** (the action passes no `--limit`),
  `client` replaced by its reverse-DNS name where one resolves, and action,
  source, rcode as words.
- Core's own page is **Reporting: Unbound DNS** under the privilege **Status:
  DNS Overview**.

## 3. What gets built

1. `lens dns`: totals plus the last 24 hours of clients, **joined onto
   identity per ten-minute slot** — the address's holder *at that time*, from
   the address windows (rule 8), never today's ARP table. Two holders in a slot:
   unattributed, as everywhere else.
2. `lens dns-device --mac a,b --hours 24|168`: every address the device held in
   the range, at most the eight most recent windows, each asked of `details`;
   top domains, blocked ones, and whether a window hit the 500 cap.
3. **Reporting: Lens: DNS**: figures, top domains, top blocked with their list,
   devices asking most. When there is nothing, the reason in one sentence:
   Unbound not the resolver (dnsmasq keeps no queries), statistics switched off
   — with where — or nothing recorded yet.
4. A lazy DNS card on the device page.
5. **Its own ACL privilege, Reporting: Lens: DNS.** A user who may read Lens but
   not core's DNS Overview must not read DNS through Lens.
6. Settings: *show what each device looked up* (on by default; nothing is
   stored — §4.64).

## 4. Load (rule 7)

Nothing on the collector. On opening the DNS page: two `stats.py` processes in
parallel (each imports pandas, ~1 s of CPU on a small box, plus a scan of up to
seven days of queries — the same work core's own page does). The device card:
up to eight `details` calls, four at a time, only when the card is opened.

## 5. Test strategy

Fixtures written from the code in §2, marked as such until the router round
replaces them with a capture (`configctl unbound qstats totals 10`). Python:
slot attribution, a shared slot refused, float slot keys, the 500 cap, a
missing or failing `stats.py`. PHP: the three "nothing here" sentences, the
percentages as strings, names from the device rows. Preview: a fake
`stats.py` answering in the same shapes for the seeded devices.
