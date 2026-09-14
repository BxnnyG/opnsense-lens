# Lens — Design: systems, gaps, decisions

> Living document. Process: [PROCESS.md](PROCESS.md), target picture:
> [VISION.md](VISION.md).
> **The question behind everything: "does this need more than one place?" —
> then it belongs in the identity service (S1) or the derivation layer (S4),
> never duplicated into a Volt template or a widget.**

## 0. Guiding idea

OPNsense knows *what* traffic happened and, separately, *who* is on the network
right now. It never joins the two over time. Lens is that join, plus the screens
that become possible once it exists.

| Layer | Contains | Who owns it |
|---|---|---|
| Sources | flow records, DNS queries, leases, ARP/NDP, live counters | **OPNsense core** — read only, never re-implemented |
| Identity | device ↔ MAC ↔ addresses ↔ names ↔ tags, over time | **Lens (S1, S2)** — the part nothing else keeps |
| Derivation | attribution, baselines, verdicts, plain-language sentences | **Lens (S4, S8)** — server side, in PHP/Python, once |
| Surfaces | Reporting pages, client profile, widgets, wallboard | **Lens (S5–S7, S10)** — layout only, no interpretation |

Interpretation happens below the surface layer. A template that decides what a
number *means* is a bug, because the next surface will decide differently.

## 1. Inventory — what already exists

> Verified 2026-08-29 against `opnsense/core` on `master` and against the
> `opnsense/plugins` checkout. Paths are core paths unless stated. This section
> is what an agent reads instead of guessing; it has to stay true.

### 1.1 The `Reporting` menu is a real, extendable root — confirmed by doing it

`src/opnsense/mvc/app/models/OPNsense/Core/Menu/Menu.xml:15` defines
`<Reporting order="15" cssClass="fa fa-area-chart">` with `Traffic` and
`DNS (Unbound)` under it. `OPNsense/Diagnostics/Menu/Menu.xml` merges `Health`,
`Insight` and `NetFlow` into the same node from a different model directory.
A plugin `Menu.xml` merges the same way — so `Reporting → Lens` is a supported
placement, not a hack. No plugin in the `opnsense/plugins` collection does this
(checked 2026-08-29); Lens is the first. **Verified on the router 2026-08-30**:
both roots appear, `Services → Lens → Data Sources` and `Reporting → Lens`, with
their own ACL privileges. This was risk 1 of stage 1 and it is now closed.

### 1.2 The dashboard is already modular

Core ships 33 widgets under `src/opnsense/www/js/widgets/` with three base
classes: `BaseWidget.js`, `BaseTableWidget.js`, `BaseGaugeWidget.js`. Thirteen
plugins in the collection ship their own widget plus a `Metadata/*.xml` — among
them `security/netbird/.../widgets/NetBird.js`, written by this operator.
Drag-and-drop, per-user layouts and persistence are **core features since
24.7**. Building a second dashboard would be rebuilding what exists (§4.3).

### 1.3 Traffic — live

| Endpoint | Gives |
|---|---|
| `/api/diagnostics/traffic/interface` | current bits/s per interface |
| `/api/diagnostics/traffic/stream` | server-sent event stream, `poll_interval` seconds |
| `/api/diagnostics/traffic/top/<interfaces>` | current top talkers by address, from pf state data |

Live only. Nothing here is retained.

### 1.4 Traffic — history (flowd / Insight)

Retained history comes from flowd, aggregated by
`src/opnsense/scripts/netflow/flowd_aggregate.py` into SQLite, and read through
`/api/diagnostics/networkinsight/{timeserie,top,export,getMetadata,getInterfaces,getProtocols,getServices}`.

**The available aggregation dimensions are fixed and small:**

| Provider | `agg_fields` |
|---|---|
| `FlowInterfaceTotals` | `if`, `direction` |
| `FlowSourceAddrTotals` | `if`, `src_addr`, `direction` |
| `FlowSourceAddrDetails` | `if`, `direction`, `src_addr`, `dst_addr`, `service_port`, `protocol` |
| `FlowDstPortTotals` | `if`, `protocol`, `dst_port` |

That is the entire retained traffic history a plugin can read. Note what is
**not** in it: no MAC address, no hostname, no device. `FlowSourceAddrDetails`
is the richest and is what per-client destinations and ports must come from.

**And now the single most important fact in this document.** Retention is
fixed in core, per aggregate and per resolution — `history_per_resolution()` in
each aggregate class. Verified against `stable/26.7` on 2026-08-30:

| Provider | 30 s | 300 s | 3600 s | 86400 s |
|---|---|---|---|---|
| `FlowInterfaceTotals` | 1 day | 7 days | 31 days | 365 days |
| `FlowSourceAddrTotals` | — | **1 hour** | **1 day** | 365 days |
| `FlowSourceAddrDetails` | — | — | — | **62 days, daily only** |
| `FlowDstPortTotals` | — | **1 hour** | **1 day** | 365 days |

*(Corrected 2026-08-30 after `configctl netflow aggregate metadata` on the live
box contradicted the first version of this table. `FlowSourceAddrDetails`
declares `resolutions() == [86400]` — it has no sub-daily resolution at all, and
expires at 62 days, not 365. The earlier table overstated it in both directions.
Read the live metadata, not an assumption about symmetry.)*

Two rows carry the whole constraint:

**Per-client volume** — `FlowSourceAddrTotals` — is five-minute for one hour,
hourly for one day, then one number per device per day for a year.

**And half of it is not per-client at all.** The aggregator writes every flow
twice, replacing `src_addr` with the *destination* on the second write:
`(if_in, <device>, in)` and `(if_out, <far end>, out)`. So the table mixes
devices with remote peers, and `if` is the only field that tells them apart. Any
query against this provider that drops the interface is asking a question it
cannot answer — found the hard way on 2026-08-30, one harvest in.

Measured on the operator's box over 21 hours, 7291 rows:

| Interface | Rows | What it is |
|---|---|---|
| `pppoe0` | 6665 (91 %) | the far ends of connections, not devices |
| `vtnet1_vlan10` · `vtnet1_vlan20` · `vtnet1_vlan21` | 474 | actual devices |
| `lo0` | 70 | the firewall talking to itself |
| `vtnet2` | 44 | the management NIC |
| `'0'` | 38, and 805 MB | **flows flowd could not attribute to an interface** |

Two of those were not expected and are recorded so nobody rediscovers them:
`lo0` appears and is not a device network, and a literal `'0'` appears for flows
with no known interface — 805 MB of them in one day, which is too much to
silently drop and too unattributable to show as a device.

**Per-client detail** — who it talked to, on which port, over which protocol —
is *only ever* a daily bucket, kept 62 days. There is no hour in which
`10.10.20.115 → 142.250.x` exists as an hourly fact. Not last week's. Not this
afternoon's. Not the last five minutes'.

Interface-level history is generous; device-level history collapses almost
immediately, and device-level *detail* is never fine-grained at all. That is
exactly backwards from what this plugin is for.

Three consequences, and none of them is optional:

1. **"Show me the console's traffic last Tuesday, hour by hour" is impossible
   from core data.** Only a daily bar exists. Any design that assumes otherwise
   is designing against data that has already been deleted.
2. **S8 (baselines) cannot be built on flowd.** A per-device, per-hour-of-week
   baseline needs weeks of hourly buckets; flowd keeps them for a day. So S2's
   collector does not merely observe identity — it must **harvest the hourly
   per-client buckets before they expire**, at least once every 24 hours, and
   keep its own rollup. Miss a day and that day is gone permanently.
3. **The time-travel slider is honest only at interface granularity.** Beyond
   24 hours it can offer days, not moments — and it must say so rather than
   quietly resample.
4. **"What is this device talking to right now" is a different mechanism
   entirely.** flowd cannot answer it at any resolution finer than a day. Live
   destinations must come from pf state data — `/api/diagnostics/traffic/top`
   and the firewall state endpoints (§1.3) — which is live-only and keeps
   nothing. So the client profile page (S5) carries two destination panels
   fed by two unrelated sources with two different time semantics, and the
   page must never let them blur into one another.

This was found on 2026-08-30 by reading the aggregate classes during the first
preflight, before any code existed. Had it been found at stage 12 it would have
cost a rewrite of the store and the baseline engine.

### 1.5 Identity — present tense only

| Source | Endpoint | Gives |
|---|---|---|
| ARP | `/api/diagnostics/interface/searchArp` | IPv4 ↔ MAC ↔ interface, vendor, hostname |
| NDP | `/api/diagnostics/interface/searchNdp` | IPv6 ↔ MAC |
| Kea leases | `OPNsense/Kea/Api/Leases{,4,6}Controller` | lease, hostname, MAC, expiry |
| Dnsmasq leases | `OPNsense/Dnsmasq/Api/LeasesController` → `configctl dnsmasq list leases` → `/var/db/dnsmasq.leases` | same, other DHCP server |
| MAC vendor database | `configctl interface list macdb` | OUI → vendor, which is where device icons come from |

The operator's box runs **dnsmasq** for both DNS and DHCP (confirmed
2026-08-30), so `/var/db/dnsmasq.leases` is the live path here. Kea and BIND are
a possible future on the same box — which is why nothing may hard-code one
server (§4.15).

All of these describe *now*. Nothing keeps a history of which MAC held which
address last Tuesday. **This is the gap the plugin exists to close.**

### 1.6 DNS — and the trap in it

`OPNsense/Unbound/Api/OverviewController`: `searchQueries`, `rolling`,
`totals`, `getPolicies`, `isEnabledAction`, `isBlockListEnabledAction`,
`resetAction`, backed by `configctl unbound qstats *` over
`/var/unbound/data/unbound.duckdb`. Queries carry the client address and which
policy blocked them — exactly enough to attribute DNS behaviour to a device once
S1 exists.

**But that is Unbound's, and only Unbound's.** The operator resolves with
dnsmasq, and dnsmasq in OPNsense exposes leases and nothing else: there is no
query statistics store, no equivalent API, no blocklist attribution. BIND is a
third shape again. So the DNS view has no single source, and the box the plugin
was designed for is the one that cannot feed it (§4.15).

A further trap, found the same day: **`unbound.general.stats` said "enabled" on
a box that does not resolve with Unbound.** Configuration is not evidence of a
running service. Every source check must test the process, not the config —
otherwise preflight cheerfully promises data that will never arrive.

### 1.7 IDS — noted, out of scope for now

`/api/ids/service/queryAlerts` and `getAlertInfo` exist and are searchable.
Out of the first scope by the operator's decision (§4.6). Recorded here so the
next person does not have to re-discover it.

### 1.8 Plugin mechanics

- `<category>/<name>/Makefile` including `../../Mk/plugins.mk`; `PLUGIN_NAME`,
  `PLUGIN_VERSION`, `PLUGIN_REVISION`, `PLUGIN_DEPENDS`, `PLUGIN_COMMENT`.
- Pure-UI plugins with no FreeBSD port behind them exist — the themes.
- `src/etc/inc/plugins.inc.d/<name>.inc` supports a `_cron()` hook; used by
  `dns/rfc2136`, `security/q-feeds-connector` and `www/nginx`. That is how the
  collector gets scheduled.
- `src/opnsense/service/conf/actions.d/actions_<name>.conf` for configd actions.
  **A dotted action name is addressed with spaces, not dots:** `[collect.status]`
  is invoked as `configctl netflow collect status`, and core does the same from
  PHP — `configdRun('interface list ifconfig')` for `[list.ifconfig]`. Getting
  this wrong returns `Action not allowed or missing`, which reads like a
  permission problem and is not (verified 2026-08-30).
- Everything under `src/` is installed into `/usr/local`. There is no exclude.
- Current release train: `stable/26.7`. The operator's box runs **26.7.1_1
  (amd64)**, confirmed 2026-08-30.
- **The root shell is `csh`, not `sh`.** It does not understand `$(...)` or
  `2>&1`. Any command handed to an operator must be shell-independent, and any
  script must produce its own output file rather than rely on a pipeline. The
  first run of `tools/lens-preflight.sh` failed on exactly this
  (`Illegal variable name`, 2026-08-30). The same trap applies to anything the
  plugin's documentation or its configd actions ever tell someone to type.

## 1b. Status overview (maintain at EVERY stage)

| System | Status | Rest / note |
|---|---|---|
| S0 · Package skeleton & walking skeleton | ✅ (stage 1) | installed and click-tested on the router 2026-08-30 as `os-lens-0.1_1` |
| S1 · Identity service | ⏳ | the spine; nothing before it is meaningful |
| S2 · Own store & collector | ✅ (stage 4) | `/var/db/lens/lens.sqlite`, observe every 5 min, harvest every 30 |
| S3 · Preflight & setup wizard | ⏳ | hand-run scripts exist in `tools/` (2026-08-29) as the spec |
| S4 · Traffic attribution | ⏳ | joins §1.4 onto S1 |
| S5 · Client profile page | ⏳ | |
| S6 · Reporting overview | ⏳ | |
| S7 · Dashboard widgets | ⏳ | |
| S8 · Baseline & verdicts | ⏳ | needs weeks of S2 data before it may speak |
| S9 · Correlation timeline | ⏳ | IDS slot left open (§4.6) |
| S10 · Wallboard / kiosk | ⏳ | |
| S11 · Command palette | ⏳ | needs S1 as its index |
| S12 · DNS view | ⏳ | needs Unbound reporting on (S3) |
| S13 · Load budget | ⚾ rule | never "done" — see PROCESS edge case 3 |
| S14 · Privacy & retention | ⚾ rule | never "done" — see PROCESS edge case 5 |
| S15 · Test & gate chain | ⚾ rule | never "done" |

## 2. Systems & gaps

### S0 · Package skeleton
**Purpose:** prove the whole chain — build, install, menu, ACL, page, gates —
before any feature depends on it.
**Today:** built 2026-08-30. `net-mgmt/lens` produces `os-lens`, two menu roots
(§4.12), two ACL privileges, one read-only API action and `SourceProbe`, which
turns a configd reply into "answering" or "silent" and is covered by 13 tests.
Not yet installed on a router, so the package, the menu placement and the ACL
are unproven.
**Plan:** `net-mgmt/lens`, package `os-lens`, one page under `Reporting → Lens`
that renders a single true sentence. Upstream build machinery (`Mk/`,
`Scripts/`, `Templates/`, `Keywords/`) copied in at a recorded commit (§4.1).
**Open:** whether `make lint` / `make style` can run without a core checkout, or
whether the netbird `tests/gates/run.sh` approach has to be lifted across.

### S1 · Identity service
**Purpose:** give every device on the network one identity that survives an
address change, and let the operator name it.
**Today:** the store, its collector and the first screen exist (stages 4 and 5,
2026-08-30). Reporting: Lens opens with the devices themselves — name, vendor,
every address held and on which segment, presence, and how long each has been
known. Naming is observed only; the operator's own labels are stage 6.
**Plan:** an observation table (MAC, address, interface, hostname, source,
first seen, last seen) fed by the collector, collapsed into a device record.
Key on MAC where one exists; fall back to a stable-address identity where it
does not.
**A device holds a *set* of addresses, not one address with a history.**
Confirmed on the operator's network 2026-08-30: `42:c5:38:e1:54:c7` is one admin
PC deliberately present in MGNT *and* HOME with an address in each, at the same
time. Servers with a management interface and the firewall itself (eight
addresses across eight VLAN interfaces) are the same shape. So the data model is
device → many concurrent (address, interface) pairs, each with its own validity
window — not device → current address plus a change log. Getting this wrong
would have been invisible until the top-talkers list quietly listed the admin PC
twice, at half its size each. Operator-owned fields — display name, icon, tags, notes — live beside
the observed ones and are never overwritten by an observation.
✅ **Decided (§4.17, 2026-08-30):** keyed on MAC. Ten hours of observation on
the operator's network produced zero address collisions and zero fragmentation.
Provisional — a quiet Saturday night hides what a week of rejoining devices
shows. **Since 2026-09-10 that re-check is continuous rather than a date:
`IdentityHealth` counts what would overturn it and says so on Services: Lens
(§4.36).**

**The original concern, kept because it is still the failure mode:** MAC
randomisation. Modern phones present a
different MAC per SSID and rotate it; IPv6 privacy extensions rotate addresses
hourly. One device will look like many. The plugin must not present a device
list that grows by ten entries a week and quietly lies. Candidate mitigations to
evaluate against real data, not in the abstract: treat locally-administered MACs
(the `x2/x6/xA/xE` bit) as a distinct, clearly-labelled class; offer manual
merge; cluster on DHCP fingerprint plus hostname. **No design is committed
until observations from the operator's own network exist** — which is one more
reason S2 ships before S1's UI.

### S2 · Own store & collector
**Purpose:** keep the things nothing else keeps, and keep them cheaply. Since
2026-08-30 that is two duties, not one.
**Duty 1 — observe identity.** MAC, address, interface, hostname, lease, over
time. Nothing in OPNsense keeps this (§1.5).
**Duty 2 — harvest flow buckets before core deletes them.** Per-client hourly
buckets live for **24 hours** (§1.4). Any per-device history with more
resolution than one-number-per-day, and every baseline in S8, depends on Lens
copying those buckets out in time. This is not an optimisation: a missed day is
gone from the universe. The harvest interval therefore has a hard ceiling
imposed by core, not chosen by us.
**Plan:** one SQLite database under `/var/db/lens/`. Written only by a Python
collector run from the `_cron()` hook and by configd actions — never by the web
process directly. Schema versioned and migrated. Retention configurable, with a
documented default and a purge action (S14).
**Decided (stage 4):** observe every 5 minutes, harvest every 30, prune nightly
at 04:17. The harvest resumes from the last bucket *stored*, not from when it
last ran, so a failed run or a box that was off costs nothing as long as it
returns within core's 24 hour window. It never stores the hour still being
written, because a partial hour recorded once and never revisited would freeze
whatever had accumulated when the collector happened to run.
**Open:** what a run costs on the operator's 2-core box. Measured, not assumed
(§4.8) — the page shows it per duty.

### S3 · Preflight & setup wizard
**Purpose:** *anyone* installs the plugin and it works — not just an operator
who already configured NetFlow. This is a product requirement (§4.13), not an
accommodation for one box.
**Today:** exists as two hand-run scripts, `tools/lens-preflight.sh` and
`tools/lens-observe.sh` (2026-08-29). They are the executable specification for
this system: whatever they check, the wizard checks.
**Plan:** two halves.
*Preflight* reports, per source (NetFlow capture, flowd collection and
aggregation, Unbound reporting, which DHCP server, ARP/NDP), whether it is on,
how far back its data actually goes, and — in one sentence each — what Lens can
and cannot show without it.
*The wizard* walks a new installation through switching the missing ones on,
one screen per source, each stating its cost before the switch: disk, CPU, and
for DNS query logging the fact that it records every name every device looks
up. It never enables anything on install and never enables anything the
operator did not press (§4.5).
**Verified mechanics (2026-08-29):** NetFlow lives at `//OPNsense/Netflow` in
`config.xml` — `capture/interfaces`, `capture/egress_only`, `collect/enable`,
`activeTimeout`, `inactiveTimeout`. Services are driven through
`configctl netflow {status,collect.*,aggregate.*,cache.stats,flush}`; aggregate
databases live in `/var/netflow`, raw flows in `/var/log/flowd.log`. Unbound
reporting is `//OPNsense/unboundplus/general/stats`, its store is
`/var/unbound/data/unbound.duckdb` (DuckDB, not SQLite).
~~read through `configctl unbound qstats {rolling,clients,totals,details,query}`~~
— **corrected 2026-08-30: that line was read out of an actions file and never
executed. `configctl unbound qstats clients` answers `Execute error` on both of
the operator's boxes.**

**Why, established 2026-09-12 from `actions_unbound.conf`:** every `qstats`
action takes parameters, and configd refuses the call without them. The real
forms, all of them `/usr/local/opnsense/scripts/unbound/stats.py`:

| action | arguments | gives |
|---|---|---|
| `qstats rolling` | `--interval --timeperiod` | queries over time |
| `qstats clients` | `--interval --timeperiod --clients` | the same, per client |
| `qstats totals` | `--max` | top queried domains |
| `qstats details` | `--limit` | recent individual queries |
| `qstats query` | `--client --start --end` | one client's queries in a window |

`qstats details --limit` and `qstats query --client` are what a per-device DNS
view needs; `qstats totals` answers "what is looked up most" and, with the DNSBL
tables, what was blocked. **The output shape of each is still unseen** — the
next thing to run is `configctl unbound qstats totals 10`. The DNS view (S12) does not get designed until the real
call and its output shape have been seen. An inventory entry that was never run
is a guess with a citation, which is worse than an admitted gap — this one sat
in the document for a day looking verified.

**NetFlow service control, verified 2026-08-30 by reading
`actions_netflow.conf` on both boxes:** `netflow {start,stop,restart,status}`
drive `/usr/local/etc/rc.d/netflow`; `collect.*` drive `flowd`; `aggregate.*`
drive `flowd_aggregate`, plus `aggregate.repair`. Two readers Lens does not use
yet are worth remembering: `aggregate.top` runs `get_top_usage.py` with
`--key_fields --value_field --filter --max_hits`, and `aggregate.export` runs
`export_details.py`. `aggregate.fetch` takes a sixth argument, `--sample`, which
Lens's own call omits and which is evidently optional.
**Open — and sharper than it first looked:** which interfaces to propose for
NetFlow capture. The operator's own box (confirmed 2026-08-30) has **nine
routed segments** — MGNT, IPMI, HOME, IOT, GUEST, SERVER, NAS and LAB as VLANs
on `vtnet1`, plus `wt0` (NetBird) — behind a PPPoE WAN (`pppoe0`) and a separate
management NIC (`vtnet2`). That is not an edge case, it is the target user.
Capturing all of them records every inter-VLAN flow on both its ingress and its
egress interface, so a NAS-to-HOME transfer is counted twice and burns the
100 MB cache (§1.4) at double rate — which directly shortens how far back every
chart in this plugin can look. `capture/egress_only` exists precisely to stop
WAN traffic being double-counted and must be set to the WAN.
So the wizard cannot present a checkbox list and hope. It has to propose — with
a reason on screen — and the proposal has to trade breadth of coverage against
depth of history, out loud. This is the first real design question S3 owns.

### S4 · Traffic attribution
**Purpose:** turn `src_addr` into a device, everywhere.
**Plan:** join `FlowSourceAddrTotals` / `FlowSourceAddrDetails` (§1.4) against
S1's address history *at the time of the bucket* — not against the current ARP
table, which would attribute last week's traffic to whoever holds the address
today. **Then sum across every address the device held**, because a multi-homed
device (S1) produces one flow row per address: an admin PC in two VLANs appears
as two source addresses and must be reported as one device at full size, never
as two devices at half. The per-segment split stays available underneath — "this
device did X on MGNT and Y on HOME" is a real question — but the headline number
is the device's. Attribution confidence is a first-class value and is shown, because
sometimes the honest answer is "an address that was not leased to anyone we
know".
**Open:** unattributable traffic must have a visible home rather than being
silently dropped from totals.

### S5 · Client profile page
**Purpose:** everything known about one device, on one page.
**Plan:** header with identity, tags and verdict; traffic over time; top
destinations and services; DNS activity; lease and address history; online
timeline. Deep-linkable, because every other surface links here.

### S6 · Reporting overview
**Purpose:** the landing page under `Reporting → Lens`.
**Plan:** the "weather report" — a short paragraph in plain language, the top
devices, what changed against baseline, and the timeline. Written by S8's
templates (§4.7), so the page lays out sentences it does not compose.

### S7 · Dashboard widgets
**Purpose:** put Lens where the operator already looks.
**Plan:** widgets for the core dashboard, on core's base classes, modelled on
`security/tailscale` and the operator's own `NetBird.js`. Candidates: top
devices now, a device that is unusual right now, DNS block rate. Each must
survive the dashboard's own refresh cycle and must not poll expensively.

### S8 · Baseline & verdicts
**Purpose:** green / amber / red without the operator configuring thresholds.
**Depends on S2's harvest, absolutely.** Core keeps per-client hourly data for
one day (§1.4); an hour-of-week baseline needs weeks of it. If the harvest is
not running, this system cannot exist — no amount of later work recovers the
data.
**Plan:** per device, per hour-of-week, a robust central tendency and spread
(EWMA plus median absolute deviation) over S2's own rollups. **It stays silent until it
has enough history to be right** — a learning period that is stated on screen
and counted down, not hidden. A wrong amber in week one costs the feature its
credibility permanently.
**Open:** the exact learning threshold. Proposal: three occurrences of the same
hour-of-week bucket, i.e. roughly three weeks.

### S9 · Correlation timeline
**Purpose:** "what happened at 14:32" as one horizontal answer.
**Plan:** one time axis, several stacked lanes — traffic peaks, DHCP events,
DNS blocks, and a lane left empty and labelled for IDS alerts (§4.6). Click a
point, get the device.

### S10 · Wallboard / kiosk
**Purpose:** a second screen in the room that is worth looking at.
**Plan:** a route without menu chrome, large type, auto-cycling panels, a
read-only ACL role. Explicitly the place where the flow diagram and, later, the
map live.

### S11 · Command palette
**Purpose:** jump to any device, tag or page without menus.
**Plan:** keyboard-triggered overlay over an index built from S1. Client-side
only, no new endpoint beyond a search action.

### S12 · DNS view
**Purpose:** what devices ask for, and what got blocked.
**Blocked on a decision, not on effort (§4.15).** The full view needs Unbound;
the operator's box runs dnsmasq, which offers no query data at all (§1.6). So
this system's first question is not "what does the feed look like" but "what
does Lens show a dnsmasq user" — and "nothing" is a legitimate answer, provided
it is said on screen rather than discovered.
**Plan (Unbound present):** live query feed with category badges, per-device
heatmap by hour and weekday, and — on clicking a blocked entry — which policy
caught it, from `getPolicies` (§1.6).
**Open:** the "allow for 5 minutes" button writes to Unbound and triggers a
reconfigure. That crosses the read-only line (§4.9) and is deferred until the
rest of the DNS view has proven itself.

### S13 · Load budget ⚾
**Purpose:** the plugin must never be the reason the firewall is slow.
**Rule:** no query on a page load may scan an unbounded table; every list is
paginated server-side; the collector's cost is measured on the operator's real
hardware and recorded in the ROADMAP operations notes. Any stage that adds a
query states what it costs.

### S14 · Privacy & retention ⚾
**Purpose:** this plugin builds a complete behavioural record of every person in
the household or office. That is not a side effect, it is the product.
**Rule:** every stored field is listed in one place with its retention; every
data source is individually switchable; a purge action exists and works; the
DNS query log is opt-in with the consequence spelled out on the switch, not in
a manual. In a commercial setting this is GDPR-relevant data and the plugin must
not make it accidental.

### S15 · Test & gate chain ⚾
**Rule:** recorded fixtures for every external API shape, so behaviour is
provable without a router. PHPUnit for derivation, and the same style/lint gate
discipline the netbird plugin arrived at.

## 2b. Idea store (unprioritised)

From the operator's brief, 2026-08-29. Kept verbatim in intent so nothing is
lost. Feasibility notes are from the same day's review; they are judgements, not
decisions.

**Wanted, feasible, not yet scheduled**
- Live Sankey WAN → VLAN → client. Feasible at a 5–10 s cadence from live top
  talkers; *not* per-packet and must not pretend to be.
- Geo world map of destination countries. Needs GeoIP, which means a MaxMind
  key belonging to the operator and a periodic download. Hard external
  dependency — worth it, but not early.
- Time-travel slider across the dashboard. Works only for historised layers
  (flow, DNS, identity). Live-only panels have no past and must grey out rather
  than lie.
- Reputation badges (Tor exit, cloud range, known bot). Only from periodically
  downloaded lists and existing OPNsense aliases. A live lookup per alert would
  leak the operator's traffic to a third party once per click.
- Layout export / import as a file.
- Weekly report as printable HTML.
- Compare two devices side by side.
- Automatic grouping by vendor or behaviour.
- Onboarding wizard that switches on the widgets matching a stated interest.
- Achievements ("30 days without an incident"), ambient sound on critical
  events. Cheap, late, opt-in.

**Wanted, but not as described**
- "AI plain-language summaries" and natural-language search → deterministic
  sentence templates instead (§4.7).
- Kill-chain visualisation → incident clustering plus the category Suricata
  actually reports; the rest would be invented structure over real data.
- Template marketplace → export/import only (VISION, deliberately not).

## 3. Prioritisation

By effect, not by effort:

1. **S0** — until a package installs and a page renders, every estimate is
   fiction.
2. **S3 before S2 before S1's UI** — the operator's box may be collecting
   nothing today. Every week without a collector is a week of history the
   plugin will not have when its interesting features arrive. Switching the
   sources on and starting to collect is therefore worth more, right now, than
   any screen.
3. **S1 + S4 + S5** — the first thing that is genuinely impossible in core.
4. **S6 + S7** — where the operator actually looks.
5. **S12, S8, S9** — depth, once there is data with age.
6. **S10, S11**, then the idea store.

## 4. Decisions

> Numbered and dated so plans can cite them. Never renumbered.

### §4.1 — Own repository, not the plugins fork (2026-08-29, agent's call at the operator's request)
**Question:** put Lens in `BxnnyG/opnsense-plugins` next to the netbird work, or
in a repository of its own?
**Decision:** own repository. The build machinery (`Mk/`, `Scripts/`,
`Templates/`, `Keywords/`, `LICENSE`) is copied from `opnsense/plugins` at a
recorded commit so `make package` behaves identically; refreshing it is a
deliberate, logged operation.
**Rationale:** the fork's purpose is work that is *going upstream* — three
netbird pull requests are open against it. Lens is explicitly not going upstream
(§4.2). Mixing the two makes both worse: rebases against upstream drag along a
plugin that will never be merged, releases and tags collide with upstream's, and
anyone landing on the fork cannot tell what it is. Branches would separate the
code but not the identity: Lens needs its own README, its own issues, its own
tags and its own package feed. The cost — tracking `Mk/` by hand — is small and
rare.
**Consequences:** the netbird fork stays clean. Lens gets a `net-mgmt/lens`
directory in its own repository. Upstream build-machinery changes are a
maintenance item in the BACKLOG.

### §4.2 — Not an upstream pull request (2026-08-29, agent's recommendation, operator's call)
**Question:** submit Lens to `opnsense/plugins`?
**Decision:** no, not as a plugin.
**Rationale:** it overlaps `Reporting` and the dashboard, both of which core
owns and is opinionated about; plugins in that collection are almost without
exception configuration UIs for a FreeBSD port, and the only pure-UI precedent
is the themes; and it reads across four foreign subsystems, a coupling the
plugin framework does not model — which is precisely the shape of thing core
answers with "that belongs in core".
**Consequences:** distribution is the operator's own package feed. Individual
pieces may be offered to *core* later on their own merit — the identity join
(S1) is the obvious candidate, being small, useful and free of UI opinion. That
possibility is a reason to keep S1 clean of Lens-specific assumptions.

### §4.3 — Supply the core dashboard, do not build another (2026-08-29)
**Question:** the brief asks for modular drag-and-drop widgets with saved
layouts. Build that?
**Decision:** no. Core has had exactly that since 24.7 (§1.2). Lens ships
widgets into it.
**Rationale:** rebuilding it costs months and produces a second, worse
dashboard the operator has to choose between.
**Consequences:** S7 is widgets, not a framework. The wallboard (S10) is a
separate, deliberately non-dashboard surface and is where the layout freedom
actually goes.

### §4.4 — Own store for identity, core APIs for everything else (2026-08-29)
**Question:** collect our own flow data, or read core's?
**Decision:** read core's flow, DNS and lease data through its APIs. Store only
identity observations, operator-owned fields (names, tags, notes) and computed
baselines.
**Rationale:** duplicating flowd would double the write load on a small disk to
produce the same numbers. What core genuinely does not keep is identity over
time (§1.5) — so that, and only that, is ours.
**Consequences:** Lens's usefulness is bounded by core's retention settings for
flow and DNS data, and S3 has to surface that honestly.

### §4.5 — Lens may enable its own data sources, and nothing else (2026-08-29)
**Question:** the operator asked for "one-click install of netflow" rather than
building on a badly configured box. Does a reporting plugin get to write
configuration?
**Decision:** yes, for exactly one class of change — switching on the sources it
reads (NetFlow capture and aggregation, Unbound reporting) — always as an
explicit action the operator triggers, never on install, never silently, always
with the cost stated and always reversible.
**Rationale:** the alternative is a plugin that shows empty pages and blames the
operator. The risk is a reporting tool that reconfigures a firewall behind
someone's back; naming the boundary narrowly is what keeps that from creeping.
**Consequences:** S3 exists and is early. Every write path outside this class is
a violation, not a feature request.

### §4.6 — IDS out of the first scope (2026-08-29, operator)
**Question:** four of the six idea groups in the brief were IDS-shaped. In?
**Decision:** out for now. The correlation timeline (S9) reserves a lane for it
so that adding it later is an addition, not a rewrite.
**Rationale:** operator's scope call. Suricata may not even be running on the
box.
**Consequences:** no IDS reading, no alert feed, no reputation badges yet. §1.7
records what was found so the work is not repeated.

### §4.7 — Deterministic sentences, not a language model (2026-08-29, agent's recommendation, accepted by scope)
**Question:** the brief asks for plain-language summaries and natural-language
search.
**Decision:** generate the sentences from templates over measured values and
baselines. No model, local or remote.
**Rationale:** a remote model means internal hostnames, addresses and browsing
behaviour leaving a security appliance; nothing capable runs locally on this
class of hardware. Templates over S8 give the same reading experience, are
provable line by line, and can be tested with fixtures.
**Consequences:** S8 owns the sentences. Every sentence must be traceable to the
numbers that produced it, and that traceability is testable.

### §4.8 — The load budget is a rule, not an optimisation (2026-08-29)
**Question:** how much of the box may Lens use?
**Decision:** it is a standing constraint (S13) checked at every stage, with the
cost of each new query stated in its plan and measured on the operator's real
hardware.
**Rationale:** on a router, a reporting tool that costs throughput has negative
value. This is the failure mode that kills such plugins, and it arrives
gradually.
**Consequences:** no unbounded scans on page load; server-side pagination
everywhere; the collector's runtime is recorded in the ROADMAP operations notes.

### §4.9 — Read-only by default (2026-08-29)
**Question:** where is the line, given §4.5?
**Decision:** Lens reads. It writes only source-enablement (§4.5) and its own
store. It never writes firewall rules, routes, aliases, or DNS policy.
**Consequences:** the "allow this domain for 5 minutes" button (S12) is a
deliberate exception that has not been granted and is deferred.

### §4.10 — Working name (2026-08-29, agent, cheap to change)
**Decision:** `net-mgmt/lens`, package `os-lens`, menu entry `Reporting → Lens`.
**Rationale:** short, describes the thing (a lens on the network), no collision
found in the collection.
**Consequences:** rename cost rises sharply after the first package is
installed anywhere. If it is going to change, it changes before stage 1 ships.

### §4.11 — Documentation in English (2026-08-29, operator)
**Decision:** English, matching the sibling `security/netbird` docs and the
wider ecosystem. Never mixed within a file.

### §4.12 — Configuration under Services, views under Reporting (2026-08-29, operator)
**Question:** Lens has both settings (which sources, retention, the wizard,
connections to other subsystems) and views (overview, client profile, DNS). One
menu entry or two?
**Decision:** two. `Services → Lens` holds configuration, the setup wizard and
preflight. `Reporting → Lens` holds the views.
**Rationale:** it matches what an OPNsense user already expects from every other
plugin — configuration under Services, output under Reporting — and it means the
Reporting pages never have to carry a settings tab. It also lowers the risk in
stage 1: if the `Reporting` root turns out not to accept a plugin entry (DESIGN
§1.1 — nothing in the collection does this today), the configuration half still
lands in a placement that is proven by fifty-nine other plugins.
**Consequences:** one `Menu.xml` with two roots. Two ACL entries, because
reading the views and changing the sources are different privileges — a
wallboard user (S10) gets the views and nothing else.

### §4.13 — The plugin makes an unconfigured box work (2026-08-29, operator)
**Question:** may Lens assume NetFlow and Unbound reporting are already on?
**Decision:** no. A fresh OPNsense with nothing enabled is the *design case*,
not the exception. Preflight and the wizard (S3) are part of the product.
**Rationale:** the operator's own instruction — it should be good straight
away, not assembled on top of rubbish. Any plugin that opens on an empty chart
and expects the user to go and configure a subsystem they have never heard of
has failed before it started.
**Consequences:** S3 moves ahead of every view in the roadmap. Every surface
built later must degrade to "this needs X, here is what X costs, switch it on"
rather than rendering an empty state — PROCESS edge case 1 is the standing
check for it.

### §4.14 — Hand-run scripts before plugin code (2026-08-29, agent's call)
**Question:** the operator needs to start collecting *tonight*, and stage 1
(walking skeleton) is days away.
**Decision:** ship `tools/lens-preflight.sh`, `tools/lens-observe.sh` and
`tools/lens-observe-summary.py` as read-only shell tools in this repository, run
by hand, before any plugin code exists.
**Rationale:** data has a lead time that code does not. Every night the
collector is not running is a night of history stages 7 onwards will not have.
And S1's hardest question — how badly MAC randomisation fragments identity —
cannot be answered by reasoning, only by observing this network.
**Consequences:** the scripts are the executable specification for S3 and S2;
they and the plugin must not drift. Whatever they learn goes into §1 of this
document.

### §4.15 — The DNS source is pluggable, and may be absent (2026-08-30)
**Question:** the DNS view (S12) was designed against Unbound's reporting API.
The operator resolves with dnsmasq, and is considering BIND with Kea later. What
does Lens do?
**Decision:** DNS is one *optional source behind an interface*, not a
foundation. Unbound is the first and only implementation. dnsmasq contributes
leases and hostnames to identity (S1) and nothing to the DNS view. Where no
usable DNS source is present, S12 does not appear at all — with one sentence
saying why, and what would have to change.
**Rationale:** OPNsense supports at least three resolvers and only one of them
keeps query statistics. Hard-coding Unbound would mean the plugin's own operator
cannot use the feature — and quietly rendering an empty page is precisely the
failure VISION point 4 forbids. It also keeps the door open for BIND without a
rewrite.
**Consequences:** S12 moves behind S1, S4 and S5 in priority: it is the one
scoped-in area that the operator's own box cannot exercise, so it cannot be
click-tested here. Identity and traffic come first. If DNS becomes important
before the resolver changes, the honest options are to run Unbound alongside or
to build a dnsmasq query-log reader — the latter is a new system, not a variant,
and would need its own entry.

### §4.16 — Verify against the branch the box runs, not `master` (2026-08-30)
**Question:** the first preflight emitted four `Action not allowed or missing`
errors because the configd commands were written from `master` and, worse, with
dotted action names that configd addresses with spaces (§1.8).
**Decision:** every core fact recorded in §1 names the ref it was verified
against, and release-branch facts are checked against `stable/26.7`.
**Rationale:** PROCESS already said "verify, do not guess". It did not say
*against what*, and the gap produced four wrong commands in the very first tool
this project shipped. The dotted-name error was worse than wrong: it returned a
permissions-sounding message for a syntax mistake, which is exactly the kind of
thing that sends someone debugging ACLs for an hour.
**Consequences:** §1 entries carry their verification date and, where it
matters, the ref. The same rule applies to anything the plugin tells a user to
type.

### §4.17 — Identity is keyed on MAC (2026-08-30, decided against observations)
**Question:** BACKLOG #3 — key device identity on MAC, or on something more
elaborate, given MAC randomisation? Deliberately deferred until real data
existed rather than decided in the abstract.
**The data.** 120 snapshots, 2026-08-29 21:29 → 2026-08-30 07:20, on the
operator's nine-segment network:

| Measure | Result |
|---|---|
| Distinct MACs | 13 |
| Randomised (locally administered) | **2** |
| Addresses held by more than one MAC | **0** |
| MACs present in ≤5 % of snapshots | **0** |
| MACs holding more than one IPv4 | 2 — and **neither is churn**: the firewall itself (8 VLAN interfaces) and an admin PC deliberately homed in MGNT and HOME at once (operator, 2026-08-30) |
| MACs whose address actually changed over time | **0** |

**Decision:** key on MAC. Address history is recorded per MAC; attribution of a
flow bucket uses the address's owner *at the time of the bucket*. No clustering,
no fingerprint heuristics, no manual-merge UI in the first version.
**Rationale:** the fragmentation nightmare did not appear. Two randomised MACs
out of thirteen, both stable across ten hours, both carrying a usable DHCP
hostname (`bxy-cachyos-x8664`, `BXY-Pixel-10`). Zero address reuse means the
attribution trap that would have poisoned every chart is, on this network,
currently empty. Building merge machinery against a problem that is not present
would be inventing complexity.
**A measurement error found and fixed the same day.** The first summary counted
concurrent multi-homing as address churn, which made the firewall look like the
most unstable device on the network. Multi-homing and churn are now reported
separately — only the second threatens attribution, and on this network it is
currently zero.
**What this decision is NOT.** Ten hours, overnight, on a Saturday. Phones were
asleep, guests absent — the GUEST VLAN recorded literally zero packets.
Randomisation shows itself when a device *rejoins* a network, which happens over
days. Thirteen MACs is a household, not a proof.
**Consequences:** S1 proceeds on MAC. The observation log keeps running for a
week and is re-measured continuously (§4.36); if the randomised count climbs or address
reuse appears, this decision is revisited before S1's UI is built, not after.
The store must therefore keep the raw observation history, not just the derived
device — a merge, if ever needed, has to be reconstructible.

### §4.18 — A source is only real if its data is fresh (2026-08-30)
**Question:** preflight asked "is Unbound reporting enabled" (config: yes), then
"is unbound running" (process: yes) — and both answers were misleading. The box
resolves with dnsmasq. Unbound is running and receiving nothing.
**The evidence:** `/var/unbound/data/unbound.duckdb` had not been written for
ten hours while the box was up and resolving. Configuration said enabled.
`pgrep` said running. Only the file's age said the truth.
**Decision:** every source check has three levels and all three must pass —
configured, running, **and producing data recently**. Preflight and the wizard
report the third, and a source whose data has gone stale is reported as broken,
not as available.
**Rationale:** this is the same lesson twice in one day. Config is not process;
process is not service. A plugin that promises DNS insight because a daemon
exists will show an empty page and blame the user.
**Consequences:** every entry in S3's source table carries a "last produced
data" timestamp. Anything that has not moved within its expected interval is
amber with a sentence, never green.

### §4.19 — The collector comes before the wizard (2026-08-30, agent's call)
**Question:** the roadmap put the setup wizard (stage 3) before the store and
collector (stage 4). After the preflight ran on the operator's box, is that still
right?
**Decision:** no. Swap them. The store and collector are next; the wizard follows.
**Rationale:** two facts, one of them urgent.
*The wizard has nothing to do here.* The preflight reports three of four sources
ready and the fourth deliberately unused (§4.15). Every source this box can have
is on. The wizard serves a user who does not exist yet.
*The collector is losing data every day it does not exist.* Per-client hourly
buckets expire after 24 hours (§1.4), and nothing is harvesting them. That is not
a future cost that can be paid later by working faster — each day's hourly detail
is deleted by core and cannot be recovered by any amount of subsequent effort.
The daily totals survive, so the loss is silent and only becomes visible when S8
asks for weeks of hourly data and finds none.
**Consequences:** ROADMAP stages 3 and 4 swap. The wizard is not dropped — a
fresh install on someone else's box still needs it, and §4.13 stands. The
hand-run `tools/lens-observe.sh` keeps running until the collector replaces it.

### §4.20 — Amber is reserved for what is worth fixing (2026-08-30, operator)
**Question:** the preflight marked Unbound "needs attention" on a box whose
operator said "I only enabled it once for bug testing, I do not use it". Correct?
**Decision:** no. A source that is present but simply not adopted is grey and
factual — "unavailable, and that is a choice, not a fault". Amber is for states
the operator would want to act on. The two are told apart by evidence the box
already has: if another resolver is running, this is a choice; if Unbound is the
only one and records nothing, it is a fault.
**Rationale:** a warning that can never go green is worse than no warning. The
reader learns to skip that row, and then skips the row next to it. Preserving the
meaning of amber is what keeps the rest of the page worth reading.
**Consequences:** the same test applies to every future verdict — before an amber
state is added, name what the operator would do about it. If the answer is
"nothing, that is just how my network is", it is grey.

### §4.21 — A controller that interprets anything is a bug waiting (2026-08-30)
**Question:** `os-lens-0.1_3` shipped a page that said "10 leases from dnsmasq"
and, two rows below, "Unbound is the only resolver here". Both rows came from the
same daemon's state. Every test passed. How?
**What happened:** the change that introduced the distinction between a resolver
that is *not used* and one that is *broken* (§4.20) touched two files. It landed
in `SourceReport`, where it was covered by four tests, and silently failed to
land in the controller that supplies the key those tests set by hand. The unit
tests on both sides were green because neither of them crosses the join.
**Decision:** assembly of facts from raw replies moves into `SourceFacts`, a pure
class, and the controller is left with input and output only — configd calls,
config reads, one `stat`. Every layer that decides anything is now reachable from
a test that starts with recorded configd output and ends at a verdict.
**Rationale:** a defect that every test passes is not a testing failure, it is a
structural one. The wiring had no test because it was inseparable from `Backend`
and `Config`; making it separable is the fix, and asserting harder on the two
ends would not have been.
**Consequences:** `tests/SourceFactsTest.php` starts where the box starts. Its
first case is this exact contradiction. Anything a future controller is tempted
to decide belongs in `SourceFacts` or `SourceReport` instead.

### §4.22 — Presence is measured against the last observation, never against the clock (2026-08-30)
**Question:** the device list has to say who is on the network *now*. The obvious
implementation compares each address window's `last_seen` against the current
time. What does that page show on a box where the collector stopped running four
hours ago?
**What the box showed:** exactly that case, before the page existed. On
router-01 the store's own status read `Last observation: 16477 seconds ago`
while the observe job is scheduled every five minutes. Had the device list
compared against the wall clock, it would have rendered thirteen devices, none
of them present, and an empty network — a confident, wrong answer.
**Decision:** an address is current when *the most recent observation still saw
it*. Windows are extended with the timestamp of the run that saw them, so this
is an exact comparison, not a tolerance. When that run is older than
`OBSERVATION_STALE_AFTER` (900 s), the page says so in a banner, drops the "here
now" count from its headline, and reports what was true then.
**Rationale:** "nothing is on the network" and "nobody looked" are different
statements, and only one of them is ever true at a time. A surface that cannot
tell them apart will eventually assert the wrong one to somebody making a
decision. This is the same rule as §4.18 (config ≠ process ≠ serving) applied to
time instead of to state.
**Consequences:** every later view built on identity — the client profile, the
top-talkers list, the wallboard — takes the observation timestamp as an input,
not the clock. `DeviceReport::describe()` has no clock of its own.

### §4.23 — The vendor is looked up at display time, not stored with the observation (2026-08-30)
**Question:** device names fall back to hardware vendor when nothing announces a
hostname. Should the collector resolve the OUI and store the vendor string next
to the MAC, or should the web side resolve it every time it draws the list?
**Decision:** at display time, from core's own `configctl interface list macdb`,
keyed on the uppercased first six hex digits — the same lookup core's own DHCP
lease pages use.
**Rationale:** the OUI table is not an observation. It is a reference that gets
better with every OPNsense update, and a device recorded in March should benefit
from a table shipped in June. Storing the string freezes it, costs a column, and
creates a second source of truth for something core already publishes. The cost
is one extra configd call per page load, which the page already measures and
displays.
**Consequences:** `DeviceReport` takes the table as an argument and is testable
without it; a MAC whose OUI is unknown is shown as a MAC, never as a guess.

### §4.24 — A list of windows is not a list of addresses (2026-08-30)
**Question:** `os-lens-0.3_1` reached the operator's router and every device
listed each of its addresses twice — once "here now", once "4.8 hours ago". The
firewall itself showed twenty-four rows for twelve addresses. What went wrong?
**What happened:** nothing, in the store. The observe job had not run for 4.8
hours (see ROADMAP, the cron finding), so on the next run every address exceeded
the 900 s gap and `fold_observations` correctly opened a *second* window for it.
That is the intended behaviour and stage 7 depends on it: attributing a traffic
bucket to a device means knowing which window covers the bucket's own timestamp,
not merely that the device once held the address. The defect was that
`DeviceReport` rendered windows and called them addresses.
**Decision:** the store keeps windows; the device list folds them by (address,
interface) — earliest `first_seen`, latest `last_seen`, a count kept — and
presence is decided on the folded row. Every later view that shows a device to a
person folds; every view that attributes traffic to a moment does not.
**Rationale:** this is §S1's "one machine reported as two" one level down, and
the page carries a footer promising exactly that will not happen. A caption that
contradicts the table above it is worse than no caption. The two shapes are both
correct for their own purpose, and the mistake was letting one surface see the
wrong one — the same class as §4.21.
**Consequences:** `DeviceReport::addresses()` is the only place that folds, and
it is tested against the router's own shape. Also visible in the same screenshot
and fixed with it: the firewall's own interfaces were listed as if they were
clients, and are now labelled from the permanent-ARP flag the collector already
records.

### §4.25 — Declaring a cron job is not installing one (2026-08-30)
**Question:** `lens_cron()` returns two `autocron` entries and has since stage 4.
Neither firewall ever ran them. `grep -n lens /var/cron/tabs/root` is empty on
both, after several package installs and configd restarts — so the jobs were
never in the crontab at all, rather than being there and failing.
**What happened:** the hook is right. `plugins.inc.d/lens.inc` matches
`security/q-feeds-connector`'s `qfeeds.inc` line for line, which is what I
checked and reported. What I did not check is that the same plugin also ships
`+POST_INSTALL.post`, whose entire content is `/usr/local/sbin/pluginctl -s cron
restart`. The `_cron()` hook *declares* jobs; the install path does not
regenerate `/var/cron/tabs/root` on its own.
**Decision:** ship `+POST_INSTALL.post` with that line, and `+POST_DEINSTALL.post`
with the same — a crontab entry that outlives the package would call
`configctl lens observe` every five minutes against an action that no longer
exists. `tests/gates/run.sh` now runs `sh -n` over both; they execute as root at
install time and were previously the only unchecked code in the package.
**Rationale:** this is the failure the whole plugin is about, turned on itself.
A collector that silently does not run leaves no trace except missing history,
and missing history cannot be collected afterwards — S2's central constraint.
Two days of per-device hourly buckets on the operator's boxes are gone for good.
**Consequences:** stage 5's staleness banner (§4.22) is what surfaced it, and it
stays exactly as it is — it is the only thing on any page that would have said
so. The matching PROCESS rule: cite a precedent by its whole package, not by the
file you went looking in.

### §4.26 — Every byte is accounted for, or the number above it is not trustworthy (2026-08-30)
**Question:** attribution joins hourly buckets onto address observations. Most
buckets will not match a device. What happens to them?
**The measurement that forces the answer:** on router-01, **91% of harvested rows
carry `pppoe0`** — the far end of the flow, written by the aggregator's second
pass (§1.4). Add `lo0` (the firewall talking to itself) and the literal `'0'`
interface (805 MB of flows flowd could not place), and the majority of the input
to a top-talkers view is not a device on the network at all.
**Decision:** four outcomes, never three. A bucket belongs to a device, or it is
*the far end of a flow*, or *nobody was observed holding that address in that
hour*, or *two devices held it and the hour cannot be split*. The last three are
shown on the page, with their byte totals and the reason, directly beneath the
attributed total. An interface counts as a device network when any device has
ever been observed on it — derived from the data, not a hardcoded list of WAN
names.
**Rationale:** a table that silently drops nine tenths of its input looks exactly
like one that did not. Nothing on the page would contradict it, the totals would
be internally consistent, and someone would eventually decide something on it.
The same reasoning as §4.20 and §4.22: the plugin's value is that its numbers can
be trusted, and that is a property of what it admits, not of what it computes.
**Consequences:** `lenslib.attribute` returns both halves and a test asserts that
every octet in equals every octet out. When ambiguity is resolvable later — a
DHCP log with sub-hour resolution, say — it becomes a fourth attributed class,
not a silent reassignment.

### §4.27 — An absence is only as true as the list it was asserted from (2026-08-30)
**Question:** the preflight page told the operator "No DHCP server is running
here" on a firewall that was leasing every address on its network. Lens knew
dnsmasq and Kea. OPNsense ships three.
**Decision:** `isc-dhcp` joins the chain — `dhcpd status`, `dhcpd list leases 0`,
and `/var/dhcpd/var/db/dhcpd.leases` for the collector, which reads lease files
directly because it runs inside configd. The verdict falls through all three
before it says "none".
**Rationale:** this is a worse class of wrong than a missing feature. "Nothing is
running" is a *conclusion*, and the page presented it with the same confidence as
the facts it had actually established. Every device on that box lost its name for
it. The same reasoning as §4.18 and §4.22: before a surface asserts an absence, it
has to have looked everywhere the thing could be.
**Consequences:** any future source with more than one implementation — a
resolver, a DHCP server, a flow collector — is enumerated exhaustively in
`SourceProbe::commands()` or it does not get a "none" verdict at all.

### §4.28 — "Before Lens was watching" is not a gap in collection (2026-08-30)
**Question:** stage 7 shipped an accounting of unattributable traffic. On the
operator's second firewall it reported **129 GB** under "nothing was observed
holding that address in that hour. Usually a gap in collection."
**Why that number exists:** the first harvest reaches 23 hours back (§S2), and
identity starts the moment the collector first runs. On a box installed an hour
ago, almost every bucket predates every observation. It is not a gap; it is the
past.
**Decision:** a fifth class, `not_watching`, for buckets that closed before the
first observation ever recorded. It is described as shrinking to nothing on its
own, with nothing to fix. `unknown` now means what its text always claimed: Lens
*was* watching and still saw nobody.
**Rationale:** §4.20 again — a warning that can never go green teaches the reader
to skip that row, and then the row next to it. 129 GB of alarming grey on a fresh
install would have taught it on day one.
**Consequences:** `attribute.classify()` takes the first observation timestamp.
A box with no observations at all attributes everything to `not_watching` rather
than blaming a collector that has never had a chance to run.

### §4.29 — One inferred thing, and it is labelled (2026-08-30)
**Question:** stage 16 puts an icon on every device so fifty rows of identical
text become scannable. The icon comes from matching the vendor string and the
hostname against a list. Every other thing Lens shows was *observed*. Is this a
line worth crossing?
**Decision:** yes, once, and visibly. `DeviceType` maps a vendor or hostname to
one of seven icons and a label; the label is in the row's `title`, so the guess
can always be read out loud. **A string nothing matches produces a neutral mark,
never the nearest plausible icon.** Stage 6 lets the operator override it
permanently, at which point it stops being a guess for that device.
**Rationale:** the page was correct and unreadable, and correctness that nobody
can navigate is not much of a virtue. But the plugin's whole claim is that its
numbers can be trusted, so an inference must be visibly a different *kind* of
statement from an observation — not blended in beside it. Forcing an unknown
vendor into the closest category would be the exact failure this rule exists to
prevent: a confident, plausible, unfalsifiable wrong answer.
**Consequences:** any future inference — device grouping, "unusual" verdicts in
S8, a reputation badge — is held to the same two conditions: it says what it
inferred from, and it declines rather than approximates.

### §4.30 — What a person typed lives in its own table (2026-08-30)
**Question:** the operator's name for a device could be a column on `device`,
next to the hostname the box observed. One table, one row, one read. Why a
second table?
**Decision:** `device_label` is separate, and the collector never writes it.
**Rationale:** `device.hostname` is refreshed on every observation from whatever
DHCP last said. The day someone adds a `COALESCE` or an `excluded.hostname` to
that upsert — and stage 4's `see_device` already carries exactly such a clause —
a name a person typed is gone, silently, with no way to tell it ever existed.
Separating the tables makes that mistake impossible to make by accident rather
than merely forbidden by a comment. It is §S1's rule ("never overwritten by an
observation") enforced by shape instead of by discipline.
**Consequences:** every read that shows a device joins both and prefers the
label; every write path touches exactly one of them. Retention and purge had to
be taught about the new table before the stage shipped — a test caught both, and
the second one would have broken the S14 promise outright.

### §4.31 — A total nobody can break down is a number you believe or ignore (2026-08-30)
**Question:** the accounting from §4.26 works. On the operator's second firewall
it reports 43 GB attributed, 45 GB far end, and **95 GB** under "Lens was
watching and still nobody held that address". Router-01, on the same build,
reports 7.6 MB in that class. So it is not a bug in the join — it is something
about that network. The page says the number and offers nothing to do with it.
**Decision:** `attribute.classify()` also returns the heaviest twenty-five
(reason, interface, address) triples, with their byte total and how many hours
each appeared in. The page lists them behind a toggle.
**Rationale:** refusing to drop what cannot be explained (§4.26) was the right
half of the rule; it is only useful with the other half, which is being able to
*look at* what could not be explained. Twenty-five is deliberate — the far end
alone is tens of thousands of internet addresses, and a complete list answers
nothing. What a person needs is to recognise a pattern: one repeated subnet, one
interface, one machine behind another router.
**The likely answer on that box, stated as a hypothesis and not as a fact:** an
address routed *through* the firewall rather than attached to it has no MAC on
any of its segments and never will. Whether that is what the 95 GB is will be
read off the list, not argued from here.
**Consequences:** the `unknown` wording now names that third possibility beside
the two it already named. If the list confirms it, routed networks become their
own attributed class rather than a residue.

### §4.32 — Two views of one number share one query (2026-08-30)
**Question:** the device list totals traffic per device. The detail view totals
the same traffic per hour for one device. Two readers, same underlying join —
write it twice, or share it?
**Decision:** one SQL string, `store.ATTRIBUTION_SQL`, wrapped by both.
**Rationale:** a detail view whose total disagrees with the row that opened it
is worse than no detail view. It does not merely fail to inform; it makes *both*
numbers unusable, because the reader has no way to tell which of them lied. And
the disagreement would not arrive on day one — it arrives the first time one
copy of the join is corrected, in a commit about something else, months later.
That is the same failure as §4.21 (a change landing in one of two places) turned
into a rule about queries rather than about classes.
**Consequences:** a test compares the two paths on the same fixture, including
an hour two devices shared, which must be absent from both. Any future reader of
per-device traffic — the wallboard, a widget, an export — wraps that constant or
does not ship.

### §4.33 — Grouping narrows; search finds. They do not mix (2026-08-30)
**Question:** thirty rows reading `Proxmox Server Solutions GmbH` are one
hypervisor's worth of virtual NICs presented as thirty machines, and they push
everything else off box 2's screen. Collapsing them is obviously right. What is
not obvious is what happens when the reader then types into the search box.
**Decision:** **grouping is switched off entirely while a search is running.**
Segment chips keep it — they *narrow* a list, which is compatible with folding
it — but search *looks for one thing*, and a group that hides the match would
undo the feature it sits next to.
**Also decided, and smaller:** a set of fewer than three is not a group; an
operator's first tag beats the vendor, because a tag is a statement of intent and
a vendor string is an accident of procurement; the firewall is never grouped
under its chip vendor; and a collapsed group's total is recomputed from the
members still *on screen*, so a segment filter cannot leave a group claiming
devices that are no longer shown.
**Rationale:** every one of these is the same principle in a different place —
a surface must not assert something the data behind it no longer supports. A
group counting filtered-out members, or hiding a searched-for device, is exactly
that, and it is the kind of wrongness a reader never catches because the screen
looks orderly.
**Consequences:** the group totals are computed in the view because they depend
on the filter state, which lives there; what may be grouped at all, and under
which label, is decided in `DeviceReport` where it can be tested.

### §4.34 — "New" is a claim about the network, not about the install (2026-08-30)
**Question:** the overview strip should say how many devices appeared for the
first time in the last 24 hours. That is the one line on the page with real
security value — a device nobody put there is exactly what an operator wants to
be told about. On a firewall where Lens was installed an hour ago, the answer is
*all of them*.
**Decision:** the count is withheld until Lens has been watching for at least
**two days**, and until then the strip says how long it *has* been watching
instead. Two days is the first point at which "appeared yesterday" compares a
window against a history longer than itself.
**Rationale:** a figure that is technically correct and practically meaningless
does more damage than a missing one, because it looks like the real thing. The
first week of "12 new devices!" teaches the reader that the number is noise, and
the week it finally matters they will not look. This is §4.20's rule — a warning
that can never go green — applied to a figure that is always alarming at first.
**Consequences:** every derived figure in S6 and S8 states the window it needed
and declines outside it. The baseline in S8 already does (21 days); this is the
same rule at a smaller scale, and the two should read alike.

### §4.35 — The browser asks for the offer, not for the change (2026-08-30)
**Question:** the operator asked for a one-click fix beside each "needs
attention", with a dialogue naming what would change. That is §4.5's permitted
class of write, arrived at from the other end — not a setup wizard, a button
next to the finding that provoked it. How is it built so it stays that narrow?
**Decision:** three properties, in order of importance.
1. **The plan is recomputed server-side at apply time, and the request carries
   no values at all.** The browser posts an empty body meaning "do the thing you
   offered". If it posted the interface list, the endpoint would not be a fix
   button; it would be an unlabelled NetFlow configuration API reachable by
   anyone who can open the page.
2. **The preview names the setting in the words of the page that owns it** —
   "Reporting: NetFlow, under Listening interfaces" — with the value before and
   after, and every cost stated before the button, not after. The operator has
   to be able to find it again and undo it there.
3. **It lives on Services: Lens, not on the Reporting page** (§4.12). The views
   stay read-only; the Services page is where things get connected.
**Rationale:** the risk §4.5 named was "a reporting tool that reconfigures a
firewall behind someone's back". A button is exactly where that creep would
start, and the three properties above are what make it a button rather than a
door.
**Found while building it:** the plan offered "start keeping what is captured"
on a box where nothing was captured. Pressing it would have changed a setting,
reported success, and left the page exactly as wrong as before — the worst
outcome a fix button has. It now declines when the change would produce no data.
**Consequences:** every future fix follows the same shape: a pure planner that
can be tested without a firewall, a preview built from it, and an apply that
re-derives the plan and accepts nothing from the caller.

### §4.36 — A date in a roadmap is not a plan to check something (2026-09-10)
**Question:** §4.17 keyed identity on the MAC address after ten hours of
observation on one quiet Saturday, called it provisional, and wrote **re-check
2026-09-06** into the roadmap in three places. Today is 2026-09-10 and it had not
been read.
**Decision:** the re-check becomes a measurement the plugin takes continuously
and states on Services: Lens — `IdentityHealth`, four numbers and a verdict in
words. The diary entry is discharged and not replaced with another one.
**Rationale:** the date was not ignored through carelessness; it was written by
the same process that then had eleven days of other work to do, and a reminder
that depends on somebody re-reading a document is a reminder that fires when
convenient. It also aged badly in a second way: by the time it came due, the
store held eleven days of better evidence than the log the note pointed at, so
following the instruction literally would have consulted the worse source.
**The verdict itself refuses to be a score.** It prints what was measured, in the
order that decides it, so a reader who disagrees can see where. Two things
overturn the choice: an address held by two devices *at the same time*, and a
list growing by entries that are randomised and gone within the hour — the
operator's own "grows by ten entries a week and quietly lies". Both are counted;
neither is inferred.
**Consequences:** every remaining "re-check on <date>" in the documents is a
defect until it is either measured by the plugin or deleted. The 21-day baseline
in S8 already works this way, which is where the shape came from.

### §4.37 — A second surface for the same numbers decides nothing of its own (2026-09-12)
**Question:** the dashboard widget shows the top five devices and a summary
line. It could compute its own ordering, format its own byte counts, and decide
for itself when to warn about a stale collector. Should it?
**Decision:** no. It calls `/api/lens/devices/list` — the endpoint the Reporting
page calls — and renders the top of the list it is handed. Names, units,
ordering, the "new today" threshold and the staleness warning are all settled in
`DeviceReport`.
**Rationale:** a dashboard and a report that disagree by one place in the
ordering, or by a rounding, are worse than either alone, and nobody can tell
which is right. This is §4.32 a third time — first two queries, then a strip and
a table, now a page and a widget. At some point it stops being a rule about code
and becomes the shape of the plugin: **one place decides, every surface draws.**
**Consequences:** the widget is a hundred lines with no arithmetic in it beyond
`slice(0, 5)`.

### §4.38 — An ACL that was never extended is a page that works only for root (2026-09-12)
**What happened:** the ACL was written in stage 1 for two pages. Stage 5 added
`DevicesController`, stage 8 added `historyAction`, stage 6 added `labelAction`,
and none of them added a pattern. Everything worked for five stages, on two
firewalls, because the operator is root and root matches everything. A user
holding exactly the `Reporting: Lens` privilege would have been shown a page
that loaded, called three endpoints, received nothing, and explained nothing.
**Decision:** `tests/gates/lint_acl.py` derives the endpoint of every
`*Action()` in every API controller, plus every endpoint a widget declares, and
fails the build when no pattern reaches one. Run against the previous commit it
names all four.
**Rationale:** the defect is invisible to every test and to every person who
develops as an administrator, which is everyone. It is the same shape as §4.21 —
a change that landed in one of the two places it needed to — and the same answer:
make the join checkable rather than asking people to remember it.
**Consequences:** core's own `dashboard-acl.sh` does this and needs a core
checkout; this is the subset that applies here, and the gate now says so instead
of listing it under "not run".

### §4.39 — The setup wizard is closed, not built (2026-09-12)
**Question:** stage 3 has been on the roadmap since 2026-08-29 — a wizard that
switches the missing sources on, one screen each, cost stated (§4.5, §4.13). It
was deferred behind stage 4 (§4.19) and has sat there since. Is it still wanted?
**Decision:** closed as superseded by §4.35's fix button, and deliberately not
rebuilt as a flow.
**Rationale:** §4.13's requirement was never "there must be a wizard" — it was
*anyone installs this and it works, not just an operator who already configured
NetFlow*. Services: Lens already lists every source, says what each one makes
possible, and offers the one change that would fix it with the cost stated
first. A wizard would be the same information with a "next" button and a
beginning, which is worse in the case that actually happens: a box where three
sources are fine and one is not. Arriving at the fix from the finding that
provoked it is the better shape, and it is the one the operator asked for.
**What stays open from stage 3:** nothing structural. If a second fixable source
appears — Unbound reporting is the obvious candidate once §1's DNS call is
known — it becomes a second `NetflowFix`-shaped planner beside the first, not a
screen in a sequence.

### §4.40 — A wall display is the surface least able to survive a lie (2026-09-12)
**Question:** the wallboard shows the same figures as the Reporting page, larger
and with no controls. What is different about it?
**Decision:** it refreshes itself every minute, it renders the staleness note
prominently rather than as a footnote, and it says outright "Lens did not
answer. This screen is not current." when the request fails.
**Rationale:** every other surface has someone looking at it who came with a
question and will notice a page that stopped updating. **A wall display has
nobody standing at it.** It is the one surface where being wrong is silent for
hours, so the failure states get more room here than anywhere else, not less.
This is §4.22 taken to its end: presence is measured against the last
observation, and when that observation is old the screen has to say so louder
than it says anything else.
**Consequences:** the wallboard computes nothing. It calls the same endpoint as
the page and the widget, and `slice(0, 8)` is the whole of its arithmetic — the
third surface under §4.37.

### §4.41 — A byte total per interface is not the interesting number (2026-09-12)
**Question:** the operator asked for "how much traffic through which networks".
Core's Insight already shows bytes per interface. What does Lens add?
**Decision:** a second column — how much of each segment's traffic Lens can put a
device on — shown as one bar whose *length* is what the segment carried and
whose *filled part* is what has a name.
**Rationale:** the operator's second firewall reports 103 GB that Lens was
watching for and still could not attribute. On a plain per-interface total that
mass is invisible; it just looks like a busy VLAN. With the second column it is
immediately legible as what it probably is: **a segment whose traffic belongs to
machines that are not attached to it** — routed through the firewall rather than
sitting on it. A busy segment and a transit segment produce the same byte count
and mean entirely different things, and no total can tell them apart.
**Consequences:** the same page also settles the open question from §4.31 without
a special diagnostic — whichever segment carries the unattributed mass names
itself. `lo0` and the unplaced `'0'` interface get their own sentence each rather
than a shared footnote, because the answer is different for each.

### §4.42 — Reporting: Lens is three pages, and none of them computes (2026-09-12)
**Question:** the operator asked for more than one page, "like UniFi, or tiles".
**Decision:** Devices, Networks, Wallboard — plus the dashboard widget. All four
read from `DeviceReport` or `SegmentReport`; none of them decides a name, a unit,
an ordering or a threshold.
**Rationale:** this is the point at which §4.32 stops being a rule and starts
being the reason the plugin can grow. A fourth surface cost a controller, a view
and no new arithmetic, and it cannot contradict the other three. Had the first
page computed its own totals, each new page would have been a new chance to
disagree with the last.
**Still missing from what the operator asked for, and why:** WAN latency and
packet loss (BACKLOG #20) needs a data source Lens has never read — `dpinger` for
the gateway, and something new for public targets. "Who was blocked most" needs
`configctl unbound qstats totals`, whose output shape is still unseen (§1). Both
are named here so the gap is deliberate rather than forgotten.

### §4.43 — A range that the store cannot cover is answered, and said (2026-09-14)
**Question:** every page was hard-wired to 24 hours while the store keeps a
year, so the question never arose. With a range picker it does immediately:
what happens when someone asks for thirty days on a box that has collected
eleven?
**Decision:** the range is honoured, the eleven days are shown, and the page
says *"Lens has been collecting for 11 days, so this is that much rather than
the 30 days asked for. Nothing is missing — it had not started yet."*
**Rationale:** the three obvious alternatives are all worse. Refusing the range
makes a correct question look like an error. Silently clamping it to what exists
is the §4.22 family again — a chart that looks like a quiet month. Padding with
zeros invents data. Only the fourth answers the question and stays true.
**Two smaller calls inside it.** The list of ranges lives in `Window::CHOICES`
and an unrecognised `hours` falls back rather than being honoured, so the query
string cannot talk the API into an arbitrary window. And above **72 hours the
device chart is drawn per day**, because 720 bars is more than a screen has
pixels — with the bar's meaning printed beside it, since a chart whose bars
silently change unit is worse than one that says so.
**Consequences:** the range lives in the query string, so it survives a reload,
can be linked to, and the back button behaves. The wallboard deliberately has no
picker (§4.40): nobody is standing at it to choose.
