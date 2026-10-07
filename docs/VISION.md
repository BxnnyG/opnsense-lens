# Lens — Target picture

> State 2026-08-29. Changes rarely. When something here changes, it goes into
> the dated table at the bottom, never as a silent correction.
>
> Scope of this document: the `os-lens` plugin. Not OPNsense core, not the
> `security/netbird` plugin that shares this operator and this process.

## For whom

> Person 1 is derived from the operator's own brief (2026-08-29). Persons 2 and
> 3 are **assumptions, unconfirmed** — see the marker under each. They shape
> priorities, so correcting them is cheap now and expensive in six months.

1. **The operator of a mixed home / small-office network.** They run OPNsense
   because they wanted one place where the network is visible. They have a
   games console, a couple of phones, a TV, some IoT devices of uncertain
   character, and guests. When something is slow, loud or suspicious, the
   question in their head is never *"which IP address"* — it is **"which
   thing"**. Today OPNsense answers with `192.168.1.47`. They are willing to
   look at a screen for pleasure, not only in an emergency.

2. **The person who does not administer anything and just asks "why is the
   internet slow right now".**
   > **Assumption (unconfirmed, 2026-08-29):** this person never opens the web
   > interface. They are served indirectly — the operator answers them in
   > seconds instead of shrugging. If they *are* meant to get their own view,
   > that is a different product and §4.6 has to be revisited.

3. **The IT generalist looking after a handful of small sites.** They know what
   a NetFlow record is. They need "which device on this site is eating the
   uplink, since when, and is that normal for it" without SSH.
   > **Assumption (unconfirmed, 2026-08-29):** multi-site is out of scope —
   > this plugin sees exactly one box and does not aggregate across sites.

The plugin's job is to make person 1 able to name the culprit out loud.

## How we measure success

The operator can answer **"which device is doing that, and is that normal for
it?"** in under thirty seconds, from the couch, without opening a shell — and
opens the page sometimes when nothing is wrong, because it is nice to look at.

## How it gets there

1. **Devices, not addresses.** Every screen is keyed to a thing with a name and
   an icon. The IP address is a detail on the device, not the subject. This is
   the one capability OPNsense core does not have (§1 in
   [DESIGN.md](DESIGN.md)) and everything else in this plugin is a view onto it.
2. **Read core, don't reimplement it.** Traffic history, DNS queries, leases,
   ARP and live throughput already exist behind core APIs. The plugin joins and
   presents them. It builds its own store only for what nothing else keeps:
   identity over time, tags, and baselines (§4.4).
3. **Make itself work.** The plugin does not assume a well-configured box. It
   checks what data it can actually see, says so plainly, and offers to switch
   the missing sources on — with the cost stated (§4.5). A fresh install is
   useful on day one and honest about what it cannot yet show.
4. **Say what is missing instead of drawing an empty chart.** "Collecting since
   27 August, ask me again in a week" is a good answer. A flat line at zero is a
   bug report waiting to happen.
5. **Never become the reason the firewall is slow.** A reporting plugin that
   costs throughput has negative value on a router (§4.8). The load budget is a
   standing rule, not an optimisation for later.
6. **Explain, then delight.** The pretty parts — the flow diagram, the map, the
   weather report — sit on top of a correct join. Built the other way round,
   they are decoration over a lie.

## Deliberately NOT

> Set 2026-08-29 from the operator's idea list, which deliberately overshot the
> scope. Each entry is cheap to overturn now.

- **No LLM, no cloud analysis, no "AI summary".** On a firewall that means
  internal hostnames, IP addresses and browsing behaviour leaving the box to a
  third party, and nothing capable runs locally on this hardware. The intended
  effect — plain-language sentences instead of numbers — is delivered by
  deterministic templates over thresholds and baselines instead (§4.7). Same
  reading experience, no data leaves the box, and every sentence is provable.
- **No IDS/IPS section, for now.** Cut from the first scope by the operator
  (2026-08-29). The correlation timeline (S9) is designed with a slot for it so
  it can be added without a rewrite. *Parked until: the operator runs Suricata
  and says so.*
- **Not a replacement for the core dashboard.** OPNsense has had a modular
  drag-and-drop dashboard with saved layouts since 24.7. This plugin **supplies
  widgets to it**; it does not build a second one (§4.3).
- **Not a replacement for Reporting → Insight / Health / NetFlow.** Those pages
  stay, keep working, and stay linked. Lens adds the device dimension they lack.
- **No template marketplace.** Layout export/import as a file is cheap and will
  exist. Hosting, moderating and trusting community layouts is a product of its
  own.
- **No firewall or routing configuration.** Lens reads. The one exception is
  narrowly defined and consented to: switching on the data sources it needs
  (§4.5). It never writes a rule, a route or an alias.
- **No per-packet capture, no DPI, no TLS inspection.** The data ceiling is
  flow records and DNS names. Anything that would need to see payload is out.
- **No PDF library.** The weekly report is server-rendered HTML with a print
  stylesheet. Identical result, a tenth of the dependency surface.
- **No upstream pull request for the plugin as a whole** (§4.2). Individual
  pieces may be offered to core later, on their own merit.

## Architecture decision(s)

The heavy switches live in [DESIGN.md §4](DESIGN.md) so plans can cite them by
number. The three that shape everything:

- **§4.1** — own repository, not the `opnsense/plugins` fork.
- **§4.4** — own SQLite store for identity, tags and baselines; core APIs for
  everything else.
- **§4.5** — the plugin may enable the data sources it needs, and nothing else.

## Changes to the target picture

| Date | What changed | Why |
|---|---|---|
| 2026-08-29 | Created | — |

## The neighbour: Zenarmor (2026-09-14)

Zenarmor is the closest thing to this target picture that already exists, and it
is largely commercial. Worth being precise about what it is, because the answer
decides what is worth copying.

### It is a different machine, not a better version of this one

Zenarmor is an **inline deep-packet-inspection engine**. It takes raw Ethernet
frames through FreeBSD's `netmap(4)`, classifies them by application, domain and
web category, and can **block**. Lens reads what OPNsense already recorded and
writes nothing to the network at all.

That single difference explains almost every other one:

| | Zenarmor | Lens |
|---|---|---|
| sees | applications, domains, TLS SNI, categories | flows: address, port, bytes, and who held the address |
| can act | block, shape, per-user policy | nothing — reporting only (§4.9) |
| costs | 1 GB RAM and a dual-core minimum; **8 GB recommended** for the reporting database; ~5 MB of disk *per hour per Mbit/s* — about 6 GB a day on a 100 Mbit line | one SQLite file, ~0.8 MB/day on router-01 and ~6 MB/day on the 19-interface box |
| needs | netmap-capable NICs, Intel `em`/`igb` preferred | nothing beyond what OPNsense ships |
| free tier | non-commercial use, one policy that cannot be edited | all of it |

The disk figure is the one to sit with: Zenarmor's own documentation budgets
**per hour per megabit per second**, because it keeps a record of sessions. Lens
budgets per *device per hour*, which is three orders of magnitude smaller and is
the whole reason a year of history fits in a file on the firewall.

### Where Lens already wins, and it is not an accident

**Retention of attributed history is exactly what the paid tiers sell.** The
free edition's reporting is bounded, and longer retention is a licensing
question; on OPNsense itself, per-device hourly detail is deleted after 24 hours
(§1.4). Lens exists because of that deletion — and it keeps identity *at the time
of the bucket*, so a year-old number still names the right machine. Zenarmor
names the device too, but the history behind that name is the thing behind the
paywall.

**It runs on the operator's actual hardware.** A 2-core box with an
`8 GB recommended` reporting stack is not a deployment, it is a purchase.

**It explains itself.** Every number Lens shows says what it could not account
for (§4.26, §4.31, §4.41). That is a choice available to a free tool with
nothing to upsell.

### What is worth taking

Priority order, all recorded in [BACKLOG.md](BACKLOG.md):

1. **A time-range picker.** Everything is hard-wired to 24 hours. Zenarmor's
   reports let you pick the window, and the store already holds far more than
   any page offers to show.
2. **Drill-down as the primary gesture.** Their reports are built so every row
   opens into a narrower version of itself. Lens does this once (a device opens
   its own chart) and should do it everywhere: a segment opens its devices, an
   hour opens its addresses.
3. **Top domains, and what was blocked.** `qstats totals` and the DNSBL tables,
   once their output shape is known (§1).
4. **Scheduled/exported reports.** A weekly PDF or CSV. Already in the idea
   store; Zenarmor's version confirms people want it.
5. **A per-*person* view, not only per-device.** Their AD integration groups
   devices under a user. Lens has tags (stage 6), which are the same idea
   without a directory — grouping by tag is already there and could be the
   default view rather than an option.

### What is deliberately not worth taking

- **Inline inspection and blocking.** It crosses §4.9, it needs netmap-capable
  hardware, and OPNsense already ships Suricata for the security half.
- **A cloud account.** Lens's data never leaves the box; that is a feature.
- **An Elasticsearch-shaped appetite.** The 500 MB ceiling is a design
  statement, not a limitation to grow out of.
- **Tiering itself.** There is nothing to withhold.

## Against UniFi, Palo Alto and Firewalla as well (2026-09-27)

A second, wider comparison — what each product's best screen does, what Lens
can take from data it already keeps, and what it cannot have without DPI — is
in [PAGE-REVIEW.md](PAGE-REVIEW.md). It adds BACKLOG #36–#41 and one warning:
none of it before the eighteen unclicked stages have been on a router.

## Self-check: what would make each kind of person say "oh" (2026-09-24)

Written after the dashboard shipped, by asking of each audience the operator
named — the Apple user, the UniFi user, the Grafana user, the person who just
wants the internet to work, the IT admin — *what would they open this for, and
what would they expect to find that is not here?* Sorted by what it costs,
because a wish that needs a new data source is a different kind of promise from
one that needs a new view of data already on disk.

### What each of them opens it for

| Who | The question they arrive with | What Lens answers today | What is missing |
|---|---|---|---|
| **Apple user** | "Is everything fine, and who's home?" | presence, names, icons | one calm sentence at the top; a *who's home* view that reads like a family, not a table |
| **UniFi user** | "Show me my clients and let me click into one" | device list, detail chart, drill-down | a per-device *life story*: when it joined, which VLAN, every address and name it had |
| **Grafana user** | "Give me the time series and let me slice it" | three ranges, hourly/daily charts | a heatmap of *when* each device is active; a `/metrics` endpoint so their own Grafana can have it |
| **Just wants it to work** | "Is the internet slow, and whose fault is it?" | who is using the line | **latency and packet loss** — the half of "slow" that is not bandwidth |
| **IT admin** | "What joined, what changed, and prove it" | new-device count, CSV export, identity check | an alert when something new joins; a record of what talked on which port |

### With the data already on disk — cheapest, and most of the wow

These need no new source. The store already holds every hourly bucket and
every address window; these are views nobody has drawn yet.

- **#26 — Who's home.** A strip per device across the day, filled where it was
  present. The address windows *are* this chart; it only has to be drawn.
  Presence is the question the Apple user and the family admin actually have,
  and nothing in OPNsense answers it.
- **#27 — When is it active.** A 7×24 heatmap per device, hour of week against
  bytes. The single most Grafana-shaped view there is, and after the baseline's
  three weeks every cell has three samples behind it.
- **#28 — A device's life story.** First seen; every address, VLAN and name it
  has held, with dates. The windows record all of it; the detail modal shows
  none of it yet. This is the UniFi client page's best feature.
- **#29 — One calm sentence.** Above the dashboard cards: "Everything looks
  normal — 12 devices home, the line is quiet." or "One thing is unusual: the
  NAS moved 4 GB today." Built from the verdicts that already exist (§4.50),
  worded for someone who will read only that line.
- **#30 — A `/metrics` endpoint.** Prometheus text format, per device and per
  segment. Box 2 already runs Telegraf; the Grafana user gets everything Lens
  knows inside the tool they already live in, and Lens does not have to become
  Grafana. Read-only, same ACL as the pages.

### Needs a source Lens has not read yet

- **#20 — Latency and packet loss.** Core already runs `dpinger` for every
  gateway and exposes its results; the gateway half is a read, not a new probe.
  Public targets (1.1.1.1, 9.9.9.9) would be the first packets Lens itself
  sends, and deserve their own decision.
- **#23 — Top domains, and what was blocked.** Waiting on one `qstats` output.
- **#31 — What each device talked to, by port.** `FlowDstPortTotals` exists and
  is harvestable — but daily only, 62 days (DESIGN §1.4). Good enough for "this
  camera talks to port 8883 every day", not for "at 14:05".
- **#32 — New-device alerts.** The detection exists (§4.34). Delivery is the
  question: OPNsense has a notification and syslog path; a message that fires on
  day one for every device is §4.34's failure, so it inherits the same two-day
  guard.

### Deliberately not — and the one that hurts

- **Application names and categories** ("Netflix", "Gaming") need deep packet
  inspection. That is Zenarmor's whole engine and exactly what VISION already
  declined.
- **#33 — "Pause this device."** The single most-used feature in the UniFi and
  Apple home apps, and the one a parent would try first. It means writing a
  firewall rule, which crosses §4.9 in a way the NetFlow fix button (§4.35) does
  not: that one switches on a source Lens reads, this one changes who can reach
  the internet. **Recorded as a deliberate no for now**, because a reporting
  plugin that can cut a device off is a different product with a different
  failure mode — and because if it ever exists, it should be an opt-in with its
  own decision, not a button that slid in behind a chart.

### The order this suggests

The dashboard shipped today closes most of the *look* gap to Zenarmor. The
*feel* gap is #29 (one calm sentence) and #26 (who's home) — both cheap, both
from data already kept. The *depth* gap for technical users is #27 and #30. The
*trust* gap for everyone is #20, because "is it slow" is the question every one
of these people has eventually, and Lens cannot answer the latency half of it.

## Self-check: why it does not yet feel like "oh — OPNsense, but properly" (2026-10-07)

Asked by the operator after 0.16: *"ist schon geiler, gibt mir aber nicht das
AHH GEIL"* — the feeling of installing Zenarmor and thinking OPNsense is not so
plain after all. Written with an outside review in hand, which is answered point
by point at the end.

### The first finding is not about features

The last time anyone saw Lens on a router was 0.16, on 2026-09-25. Since then
0.17 to 0.31 were built — the design pass with one stylesheet for both themes,
the palette, Events, the DNS pages, the second wallboard, compare, privacy —
and looked at only in `tools/preview`. Twenty-five rows of the roadmap read
"awaiting router test", "looked at in tools/preview" or "waiting on the
operator". The feeling being reported is the feeling of 0.16.

That is not an excuse, it is the finding: this project has been building faster
than it has been looking, which is the exact pattern its own BACKLOG warns
against — and every design pass so far improved most from a screenshot, not
from the editor (§4.47, §4.48). `tools/round` exists for this. Nothing new
should start until it has run on both boxes.

### What the moment after installing Zenarmor actually consists of

1. **Names of services, within a minute.** Not addresses and bytes — "Netflix",
   "WhatsApp", "Steam", "iCloud". That is the feeling, more than any layout.
   Zenarmor gets it from inspecting packets. **Lens already has a way to most of
   it**: stages 36 and 44 hold, per device, what it asked Unbound. A curated map
   of domain suffixes to services (`nflxvideo.net` → Netflix) turns "who asked
   what" into "who uses which service", with bytes beside it from the same
   hour. Honest limits, said on the page: only where Unbound is the resolver,
   approximate behind shared CDNs, and blind to DNS over HTTPS. This is the
   largest gap in feeling, and the raw material is already on disk. BACKLOG #44.
2. **Something to look at in the first minute.** Zenarmor sits inline, so it
   has data at once. A fresh Lens shows "learning" and "not watching yet": the
   first observation within five minutes, the first harvest within thirty,
   the baseline after three weeks. But at the moment of install, core already
   holds 24 hours of per-device NetFlow and Unbound's own history. The
   post-install script only restarts cron. Running one observation and one
   harvest at install would put a day of history on the very first page load.
   Small, and it changes the first impression completely. BACKLOG #45.
3. **A lever.** Zenarmor's toggles are half its pull. Lens has none, by design.
   Stage 49 (pause a device) is planned in full and blocked on one decision only
   the operator can make — an exception to CLAUDE.md rule 6 and §4.9. It is the
   most-requested lever there is; the decision is overdue, not the code.
4. **Polish that only comes from looking.** See the first finding.

### The outside review, point by point

| Point | Verdict |
|---|---|
| Validate before building more | **Agreed, and first.** Twenty-five unverified stages. |
| Distribution is not real yet | **Agreed.** Stage 43 waits on the operator: a signing key, a repository secret, GitHub Pages. Until then a stranger cannot use the one-line install. |
| Kea is missing | **Half wrong, and the docs are why.** The collector has read `/var/db/kea/kea-leases4.csv` and the preflight has probed Kea since stage 4. But it has never run on a Kea box, and neither README nor VISION says it is supported. Fix the docs; test once on a box that uses Kea. BACKLOG #46. |
| Pause via an alias the admin's rule points at | **Matches the stage 49 plan.** The plan uses core's alias API, so membership is in the config and survives a reboot — a runtime-only pf table would not. Still blocked on the operator's decision. |
| Firewall health, rule analyser | **Agreed: a different product.** Not Lens. |
| Persona 3 and multi-site | **Partly.** The persona already says Lens sees one box; `/metrics` (§4.55) is the bridge to aggregate several in Grafana, and VISION should say so. |
| ntopng and Netdata missing from the comparison | **Agreed.** Below. |
| Two or three outside testers | **Agreed.** The largest unknown is whether a stranger understands the page, and nothing in the test suite can answer that. BACKLOG #47. |

### ntopng and Netdata, since experienced users already run them

**ntopng** sees flows and applications live, in depth, and is the closest thing
to what Lens shows — but it keys on addresses, keeps history in its own
database at its own cost, and puts its full historical views behind a licence.
**Netdata** is superb at the firewall's own health, second by second, and
knows nothing about which device on the network is which. What neither does is
the thing Lens is built on: **identity kept over time** — this MAC was this
phone, on this address, in this hour, a month ago — joined to traffic at the
moment it happened, on the firewall, without another database to run.

### Order, from here

1. Run `tools/round` on both boxes; read it; fix what it finds. Nothing new first.
2. Backfill at install (#45) — the empty first impression.
3. Services from DNS (#44) — the "Netflix" moment.
4. The operator's two decisions: the feed's signing and hosting (stage 43), and
   the pause exception (stage 49).
5. Kea: say it, test it once (#46).
6. Two or three outside testers (#47).
