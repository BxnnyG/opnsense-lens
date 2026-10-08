# What users ask for, and what Lens is missing (2026-10-08)

> Asked by the operator: *"Guck dir das Projekt an und schau auf die Welt — was
> wollen User, was fehlt? Mach mal Report."*
>
> Written against `0.34_1`. Every statement about core was checked against
> `opnsense/core` on `stable/26.7`, `opnsense/hostwatch` or the official release
> notes — not from memory (CLAUDE.md rule 2). Where the evidence is thin, it
> says so. Sources at the bottom.

## 0. The finding that changes the inventory: core keeps a host database now

Since **25.7.11 (January 2026)** OPNsense ships a host discovery daemon,
`hostwatch`, and it is **on by default** — the 26.1 release notes: *"The new host
discovery service 'hostwatch' is enabled by default (since 25.7.11)."* Both of
the operator's boxes run releases that have it. Nothing in DESIGN, VISION or the
code mentions it.

**What it is.** A Rust daemon that captures ARP and NDP packets with libpcap and
writes them to SQLite at `/var/db/hostwatch/hosts.db`. GUI under Interfaces:
Neighbors: Automatic Discovery, with a *Discovered Hosts* table ("Last Seen")
and a *Discovery Log* of `new station host … using … at …` and
`changed ethernet address host … moved from … to …` lines, sent to syslog.

**What it keeps** (`hostwatch/src/database.rs`): one row per
`(protocol, interface_name, ip_address)` with `ether_address`, `first_seen`,
`last_seen`, **one** `prev_ether_address` and its `prev_last_seen`. Rows not
seen for a configurable time are deleted (26.1: "configurable cleanups"). Writes
are rate-limited to one per host per 90 s unless something changed.

**How core reads it.** `configctl hostwatch dump_full` →
`scripts/interfaces/list_hosts.py -v -n`, which opens the database read-only,
returns rows newest `last_seen` first with the vendor, takes
`--last-seen-window N`, and falls back to `arp`/`ndp` when the daemon is off.
Core's MAC aliases resolve through it when it is on
(`scripts/filter/lib/alias/arpcache.py`).

**What this means for Lens:**

1. **The claim is too strong now.** DESIGN §1.5 said *"Nothing keeps a history
   of which MAC held which address last Tuesday"*, and the README and
   `pkg-descr` say identity *"exists only in the present tense"*. Core now keeps
   first and last sighting per address and one previous MAC. What it still does
   not keep is the **windows**: if two devices held `10.0.20.47` since last
   Tuesday, hostwatch knows the latest one and the one before, and nothing about
   *which hour*. Lens's join — identity at the time of the bucket — is still
   unique. The wording should say exactly that, because the first knowledgeable
   reader will check it. (§1.5 corrected in the same commit as this report.)
2. **Lens observes worse than core does.** `observe` reads `arp -an` and
   `ndp -an` every five minutes. hostwatch sees every ARP/NDP packet, and the
   manual says it gives *"a better picture of RFC 4941 addresses"* — the IPv6
   privacy addresses PROCESS edge case 2 and 7 worry about. An address used for
   three minutes between two polls is in hostwatch and not in Lens, and its
   traffic lands in "nobody held it". The ARP table also keeps an entry up to
   twenty minutes after a device is gone (FreeBSD's default max age), which is
   longer than Lens's 900 s gap. Reading hostwatch's rows with `last_seen` since
   the previous observation — the daemon's own database, read-only, the way
   `list_hosts.py` does, because the collector must not call configctl — with
   `arp`/`ndp` as the fallback is better data for less work. **Measure it**: how
   much of box-2's "unknown" pile it removes is the number that decides it.
3. **Two "new device" verdicts on one box.** hostwatch logs *new station* the
   moment it sees one; Lens's Events wait two days (§4.34). An operator who
   reads both will see two different answers. Either derive Lens's "first seen"
   from the same source or say on the Events page why the two differ.
4. **Pausing depends on it** — see §2.3.

## 1. What users actually ask for

| # | Need | Evidence | Core today | Lens today |
|---|---|---|---|---|
| 1 | **Which device used the bandwidth — by name, over time** | OPNsense forum, 2015–2018: "bandwidth monitoring", "Track historical usage per user", "Insights tracking to Host Name" (core's answer in 2017: dynamic leases are not resolved, "they are missing a feature link between them"). A new outside project, OTM (June 2026, Go, off-box, one commit), names the same goal: per-device hourly/daily usage from NetFlow plus DHCP/ARP/NDP | Insight is keyed on the address; reverse lookup only | ✅ the reason Lens exists |
| 2 | **Usage per device per month; quotas** | forum: "per IP address time period data quotas" (2017), "Monthly Traffic Totals" (2018), "how to setup for Monthly Data consumption"; answers point to vnStat, bandwidthd or an external collector | nothing per device | 24 h / 7 / 30 rolling days and CSV. No calendar month, no billing cycle. Quotas that cut off: out (§4.9) |
| 3 | **Pause a child's device from the phone; bedtime; time budget** | forum: "Parental control: easy temporary override?" — answer: *"no finished solution … would need some scripting and alias API queries"*; "Time of Day Restrictions"; "Internet budget … 4 hours / PC / day". Every consumer router app has the pause button | firewall schedules on hand-written rules | stage 49, decided (§4.74) — the alias approach the forum suggested, as a button |
| 4 | **Tell me when an unknown device joins** | forum: "New Device Alert" (users coming from Untangle); arpwatch recipes elsewhere | hostwatch writes *new station* to syslog since 25.7.11; nobody is notified | Events page; no delivery (BACKLOG #32) |
| 5 | **Presence for Home Assistant** | two integrations (Home Assistant's own OPNsense integration, `hass-opnsense`) — both from ARP; `hass-opnsense` documents that a device stays "home" until its ARP entry expires, 20 minutes by default | ARP, hostwatch | Who's home with gap logic and folded MACs; no documented endpoint for it |
| 6 | **Per-client numbers in Grafana** | the exporters that exist (AthennaMind's `opnsense-exporter`, Grafana dashboards 21113, 22569, 24738) are system- and interface-level only | — | `/metrics` built (stage 27), **never scraped** |
| 7 | **Service names, "Netflix" not `nflxso.net`** | Zenarmor's day-one effect (VISION, 2026-10-07) | — | services from DNS (§4.78), approximate and said so |

**How good this evidence is.** The forum threads are old — 2015 to 2019. That is
not because the needs went away: core never answered them, so people stopped
asking and moved to Zenarmor, ntopng or a NetFlow collector somewhere else. Reddit
was not reachable for this review. What is missing is the one source that would
settle priorities: **anyone other than the operator using Lens** (§2.1).

## 2. What is missing, ordered by what it costs a user today

### 2.1 Nobody but the operator can use it — and nobody knows it exists

- The repository is public with 0 stars, 0 forks, 0 issues, **no tag and no
  release**. A search for it finds nothing.
- The last feed build is Actions run 4 on 2026-10-07, at `461cf96` — before
  0.33 and 0.34. Whether Pages serves a feed could not be checked from here.
- **The release bookkeeping stopped at 0.31.** `pkg-descr`'s changelog ends at
  `0.31_1` while the Makefile says `0.34_1`; the ROADMAP's stage table ends at
  stage 47 and still reads "awaiting router round" for stages the round of
  2026-10-07 looked at; DESIGN §1b is "up to date against `os-lens-0.31_1`".
  CLAUDE.md lists all three as part of every release. Today a stranger reading
  the README cannot tell what works.
- VISION's persons 2 and 3 have been marked *unconfirmed* since 2026-08-29.
  BACKLOG #4 and #47 are the two items on this list that cost a forum post, not
  code, and they are the ones that would correct everything else here.

### 2.2 hostwatch: the inventory, the observation source, the wording

§0 above. BACKLOG #49.

### 2.3 A pause that actually stops something

- **pf keeps established states.** A block rule added after a connection
  started does not end it. A paused console keeps its game and the TV keeps its
  stream until the states expire — the person pressing the button sees nothing
  happen and concludes it is broken. Core has the tool:
  `POST /api/diagnostics/firewall/kill_states` with a filter
  (`FirewallController::killStatesAction` → `configctl filter kill states`, on
  `stable/26.7`). After the alias is applied, kill the states of every address
  the device holds now (Lens knows them: its open windows). Whether that is
  covered by §4.74 or needs one more line there is the operator's call — it is
  not a rule or an alias, but it is a write.
- **A MAC alias is resolved, not live.** pf matches addresses; core turns the
  MACs into addresses from hostwatch or ARP when it resolves the alias. Measure
  click-to-effect on the box and say it on the button.
- **The next request is predictable:** "bedtime" and "thirty more minutes". A
  timed pause is already in the plan (an optional end time). A recurring
  schedule is not covered by §4.74 — it changes the alias without a click at
  the moment of the change — so it would be its own decision. Recorded so it is
  not slid in later.

BACKLOG #50; the stage 49 plan carries the same note.

### 2.4 Tell someone

Core's hostwatch already detects new stations; Lens detects more — an unusual
day, an unusual upload, an outage, a degraded line. Neither tells anyone. The
cheapest delivery that stays inside §4.9: **write each Event as a syslog line
under its own facility** (a plugin declares one with `<name>_syslog()`, exactly
as `hostwatch.inc` does). Core's remote logging, or whatever the operator already
ships logs with, then carries it to ntfy, Gotify, Home Assistant or a mail
relay. No new outbound path from Lens, no new decision about sending data. A
webhook to an operator-chosen URL can come later, as its own decision like the
probes (§4.57). BACKLOG #51, the first concrete step of #32.

### 2.5 DNS that is quietly not per device

A common home setup puts AdGuard Home (the community `os-adguardhome-maxit`
plugin) or a Pi-hole in front of Unbound. Unbound then has exactly one client:
the forwarder. Lens's DNS page and its services would attribute the whole
network's questions to the firewall or the Pi-hole, and the source check would
call it fresh — the shape BACKLOG #16 warned about on 2026-08-30. Nothing in
`lenslib` looks for it. Minimum: when one asker accounts for nearly all
questions, say that a forwarder sits in front of Unbound and per-device DNS
needs clients to ask Unbound directly. Reading AdGuard Home's own query log
would be a new source and its own decision. BACKLOG #52.

### 2.6 The calendar month

Monthly totals are the oldest request in the forum. Lens has thirty rolling
days. *This month so far* and *last month*, per device and in total, with a
cycle start day for the ISP's billing date and the same CSV export, comes from
the settled days already in the store (§4.69). Cheap. Cutting a device off at a
quota stays out (§4.9). BACKLOG #53.

### 2.7 A Home Assistant recipe

Home Assistant users already wire OPNsense up for presence, from ARP, twenty
minutes late. Lens's presence is better. A README section showing a REST sensor
against Lens's presence endpoint, with a read-only API key, is documentation,
not code — once the endpoint's shape is one Lens is willing to keep stable.
Demand is a hypothesis; the testers in §2.1 will confirm or kill it.
BACKLOG #54.

### 2.8 Kea: core reads the socket, Lens reads the file

On `stable/26.7` core reads Kea's leases over its control socket
(`lease4-get-all` in `scripts/kea/get_kea_leases.py`). Lens reads
`/var/db/kea/kea-leases4.csv`, and only for names. That file is Kea's journal —
appended, last row wins, which `parse_kea_leases` does by overwriting — so the
risk is low. It is still untested on a Kea box (#46), and Kea is where core is
investing (26.7: DDNS, prefix delegation, ping check).

## 3. What not to build, even though someone will ask

- **Quotas that cut off, per-device schedules beyond the decided pause, content
  filtering.** A different product, across §4.9.
- **Another page.** `0.34_1` is about 19,600 lines in 95 files after six weeks,
  with one maintainer, one person clicking and two boxes. Every OPNsense
  release so far has broken something Lens took from core — the WAN key and the
  disk shape (§4.75), 26.1's Volt (§4.80). Each new surface adds to that bill.
  The next value is depth: hostwatch as the source, a pause that works, Events
  that reach someone, and people outside testing it.
- **Application identity against Zenarmor.** Unchanged from VISION.

## 4. The strategic risk, said once

Core has started building the identity half that Lens exists for. hostwatch is
version one: the last mapping, one previous MAC, a log. A history table in
hostwatch is a small step from there, and Insight joining onto it is the obvious
next one. If that happens, what is left of Lens's lead is presentation,
baselines, presence, events and the services map.

Two honest options, and they are the operator's (BACKLOG #55):

- **Offer the history upstream first.** BACKLOG #14 now has a natural home:
  hostwatch is BSD-2, small and maintained by core's own developer. Best for
  users; Lens then reads core's history instead of keeping its own.
- **Move Lens's distinctiveness up the stack** — presence, baselines, events,
  the pause, the weekly page — where core is unlikely to go.

Neither is urgent this week. Both are cheaper to decide before the first
outside user than after.

## 5. Order, from here

1. Catch up the bookkeeping: changelog 0.32–0.34, the ROADMAP rows, DESIGN §1b.
2. hostwatch (#49): README and `pkg-descr` wording; then a stage in which
   `observe` reads hostwatch when it runs, with the "unknown" pile on box-2
   measured before and after.
3. Stage 49 with the state kill and a measured time-to-effect (#50).
4. Tag a release, publish the feed, post to the OPNsense forum and r/opnsense
   with screenshots, and ask for three testers (#47) — one on 26.1, one with Kea,
   one with AdGuard Home in front of Unbound.
5. Events to syslog (#51).
6. The forwarder notice on the DNS page (#52).
7. The calendar month (#53).
8. The Home Assistant recipe, if the testers ask for it (#54).

## Sources

Core and hostwatch, read on 2026-10-08:

- `opnsense/docs` — [manual/neighbors.rst](https://github.com/opnsense/docs/blob/master/source/manual/neighbors.rst),
  [releases/CE_26.1.rst](https://github.com/opnsense/docs/blob/master/source/releases/CE_26.1.rst),
  [releases/CE_26.7.rst](https://github.com/opnsense/docs/blob/master/source/releases/CE_26.7.rst)
- [`opnsense/hostwatch`](https://github.com/opnsense/hostwatch) — `src/database.rs`, `src/lib.rs`, `CHANGELOG.md`
- `opnsense/core` on `stable/26.7` — `scripts/interfaces/list_hosts.py`,
  `service/conf/actions.d/actions_hostwatch.conf`, `etc/inc/plugins.inc.d/hostwatch.inc`,
  `scripts/filter/lib/alias/arpcache.py`,
  `mvc/app/controllers/OPNsense/Diagnostics/Api/FirewallController.php`,
  `scripts/kea/get_kea_leases.py`
- [OPNsense 25.7.11 coverage](https://cyberpress.org/open-source-firewall-opnsense-25-7-11/)

Users:

- OPNsense forum: [bandwidth monitoring](https://forum.opnsense.org/Archive/15_7_Legacy_Series/bandwidth_monitoring_),
  [Track historical usage per user](https://forum.opnsense.org/English_Forums/General_Discussion/Track_historical_usage_per_user),
  [Insights tracking to Host Name](https://forum.opnsense.org/Archive/17_7_Legacy_Series/Insights_tracking_to_Host_Name_),
  [Possible bug with traffic graph](https://forum.opnsense.org/Archive/17_7_Legacy_Series/Possible_bug_with_traffic_graph_),
  [per IP address time period data quotas](https://forum.opnsense.org/Archive/17_1_Legacy_Series/Are_there_any_plans_to_add_per_IP_address_time_period_data_quotas),
  [Monthly Traffic Totals](https://forum.opnsense.org/Archive/18_7_Legacy_Series/Monthly_Traffic_Totals),
  [how to setup for Monthly Data consumption](https://forum.opnsense.org/English_Forums/Tutorials_and_FAQs/how_to_setup_for_Monthly_Data_consumption),
  [Parental control: easy temporary override?](https://forum.opnsense.org/English_Forums/General_Discussion/Parental_control:_easy_temporary_override_),
  [Time of Day Restrictions](https://forum.opnsense.org/English_Forums/General_Discussion/Time_of_Day_Restrictions),
  [Parental control and AD blocking, will it ever happen?](https://forum.opnsense.org/English_Forums/General_Discussion/Parental_control_nad_AD_blocking__will_it_ever_happen_),
  [New Device Alert](https://forum.opnsense.org/English_Forums/General_Discussion/New_Device_Alert)
- Home Assistant: [OPNsense integration](https://www.home-assistant.io/integrations/opnsense),
  [hass-opnsense](https://github.com/travisghansen/hass-opnsense/) and its
  [device tracker notes](https://raw.githubusercontent.com/travisghansen/hass-opnsense/main/docs/device_tracker.md)
- Grafana: dashboards [21113](https://grafana.com/grafana/dashboards/21113),
  [22569](https://grafana.com/grafana/dashboards/22569),
  [24738](https://grafana.com/grafana/dashboards/24738);
  [AthennaMind opnsense-exporter](https://beta.pkg.go.dev/github.com/AthennaMind/opnsense-exporter)
- [OTM](https://github.com/jeeftor/otm) — a self-hosted OPNsense traffic monitor, one commit, 2026-06-01
- AdGuard Home in front of Unbound: [DietPi forum, "showing only 1 client"](https://dietpi.com/forum/t/adguard-w-unbound-showing-only-1-client-router/6217)
- Zenarmor: [reporting data retention](https://www.zenarmor.com/docs/opnsense/configuring/setting-store-duration-of-reporting-data),
  [licensing FAQ](https://www.zenarmor.com/docs/support/faq-licensing)
