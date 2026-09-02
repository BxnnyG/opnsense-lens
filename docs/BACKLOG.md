# Backlog & reflection (2026-08-29)

Sorted by **effect**, not chronologically — that is the [ROADMAP](ROADMAP.md).
This file says WHAT is worth doing and WHY.

Item numbers are identities, not positions: plans cite them, so a new item takes
the next free number wherever it belongs by effect. Never renumber.

## 0. Honest assessment — where does this project really stand?

**Strong.** The idea is right, and it is right for a specific and checkable
reason: OPNsense genuinely cannot tell you *which device* did something last
Tuesday. Not "does it badly" — cannot. Flow history is aggregated on
`src_addr` and nothing else (DESIGN §1.4); identity data exists only in the
present tense (§1.5). Every one of the operator's more ambitious ideas — the
flow diagram, the weather report, the comparison view, the timeline — becomes
possible the moment that join exists, and every one of them is decoration
without it. A project with one load-bearing idea and thirty ornaments hanging
off it is in far better shape than one with thirty ideas.

**The uncomfortable truth, in three parts.**

*First: nothing exists.* This repository is documentation. The plan reads well
because plans always read well. The netbird plugin next door took twenty-three
stages to get one status page from "text dump" to "answers the question", and
that page reads a single JSON document from a local daemon. This project reads
four subsystems, joins them across time, and stores its own data. It is
straightforwardly a larger job, and the honest estimate is months of evenings,
not weeks.

*Second: the operator's box may be collecting nothing at all.* Asked on
2026-08-29 whether NetFlow, Unbound reporting and Suricata are on, the answer
was "must look" — and the follow-up was, reasonably, "it should just be good
straight away, not built out of rubbish". Both of those are true at once and
they point at the same conclusion: **the first weeks of this project produce no
screens.** They produce a preflight page and a collector. If that feels like a
detour, consider the alternative — building the client profile page first,
finishing it, and discovering it has fourteen hours of history to draw. Data
has a lead time that code does not, so the collector goes in first even though
it shows nothing.

*Third, and this is the one that can actually sink it:* **the device identity
problem is harder than the entire visual layer, and it is invisible until it is
wrong.** Modern phones rotate their MAC address per network and over time; IPv6
privacy extensions rotate addresses hourly; static hosts never appear in a
lease. So a naive implementation produces a device list that grows by ten
entries a week, each holding a fragment of one real device's history — and
every beautiful chart built on top is then confidently, unfalsifiably wrong. The
sequencing in the ROADMAP exists because of this: collect observations first,
look at what the operator's actual network does, *then* decide how identity
works. Deciding it in the abstract is the single most likely way for this
project to fail while appearing to succeed.

**Short:** get the sources on and the collector running this week, even though
it produces nothing to look at, and do not design identity until there is a
month of real observations to design against.

## 1. P0 — Bugs (immediate)

Nothing. There is no code.

## 2. P1 — Must-have

- **#1 — ~~Find out what the box actually collects~~ ✅ done 2026-08-30.**
  NetFlow was already on since 2026-08-23 but captured only MGNT, WAN and
  WANMGNT; all eleven interfaces are now captured. dnsmasq resolves, Unbound
  runs but serves nothing (§4.18). The findings are in DESIGN §1.
- **#15 — Static hosts have no hostname, and it is not rare.** Three of the
  thirteen devices observed (10.10.10.2, .5, .9) have no DHCP lease at all, so
  identity has a MAC and a vendor and nothing a human would recognise. A further
  three lease a hostname of `wlan0` or `*`. So **six of thirteen devices cannot
  name themselves** — which makes the operator's own naming and tagging (stage 6)
  not a nicety but the thing that makes the device list readable at all.
- **#2 — Start collecting before building screens.** Every day without the
  collector is a day of history the interesting stages will not have.
  *(stage 4)*
- **#3 — ~~Decide identity against real observations~~ ✅ decided 2026-08-30
  (§4.17): keyed on MAC.** Ten hours of data: 13 MACs, 2 randomised, zero
  address reuse, zero fragmentation. Provisional — **re-read the observation log
  on 2026-09-06** before S1's UI is built. A quiet Saturday night is not a week.
- **#4 — Confirm or correct the two marked assumptions in
  [VISION.md](VISION.md)** — that the non-technical household member never opens
  the interface, and that multi-site is out of scope. Both shape priorities;
  both are currently guesses with a date on them.
- **#5 — Decide the name before the first package is installed anywhere.**
  `os-lens` is a working name (§4.10). Renaming after installation is a
  migration; renaming now is a `sed`.
- **#6 — Retention defaults and a working purge, in the same stage that first
  stores anything.** Not after. A store that grows without a stated ceiling on a
  router's disk is a future outage, and this data is personal (S14).

- **#16 — A DNS source that is technically fresh but practically empty.** On
  2026-08-30 the Unbound row flipped from "not the resolver" to `ready` because
  something wrote to its database within the hour — most likely the firewall's
  own lookups, on a box whose clients all resolve with dnsmasq. The freshness
  rule (§4.18) is right and the flip was correct by its own terms, but a DNS view
  fed only by the firewall talking to itself would be true and useless. Before
  S12 is built, decide what "enough queries, from enough clients" means. Cheap
  now, embarrassing later.

### Operator feedback, 2026-08-30 (after seeing stage 7 on both boxes)

The first real verdict on the product rather than on its correctness, and it is
the one that matters: *"die Seite zeigt viele Infos, aber ist für DAUs wenig
hilfreich bis wenig einordnenbar, im Vergleich z.B. UniFi mit deren Seiten und
Filtern."* Recorded as given, ordered by my judgement.

- **#17 — Legibility. The page is a table, and it should be a view.** ⚠️ **This
  is now the top of the list, above any new data.** Lens can already answer more
  than it can show: 52 rows of equal visual weight, no grouping, no filter, no
  search, no icons, one font size. UniFi's advantage is not its data — it is that
  a person can find one device in three seconds. Concretely: a filter and search
  box, grouping by segment, device-type icons derived from the vendor, a size
  cue for traffic instead of a number in a cell, and a detail view per device.
  Blocks stages 8 and 9, and arguably comes before finishing 6.
- **#18 — One-click fix for every "needs attention".** Where the preflight says
  NetFlow is not capturing an interface, offer the button that captures it —
  with a dialogue naming exactly which setting changes, before it changes. This
  is the wizard from §4.13 and stage 3, but arrived at from the other end: not a
  setup flow, a fix button next to the finding that provoked it. Crosses the
  read-only line (§4.9), so it is an explicit, per-action, confirmed write.
- **#19 — Group the hypervisor.** ✅ built 2026-08-30 in `0.6_2` (§4.33); the
  treemap remains parked.
  <br>Original: Box 2 shows ~30 `Proxmox Server Solutions
  GmbH` guests in a flat list. They are one machine's worth of virtual NICs and
  should collapse into a group that can be expanded, either by vendor OUI or by
  a tag the operator sets. A treemap was suggested and is the right shape for
  "who used the bytes" once grouping exists.
- **#20 — Reachability: latency and packet loss.** Gateway, and named public
  resolvers (1.1.1.1, 8.8.8.8, 9.9.9.9). Not currently in any system in DESIGN.
  It is genuinely a different data source — `dpinger` already runs on the box for
  the gateway, the public targets would be new probing — so it needs its own
  system entry and its own decision about writing traffic, however small, from a
  reporting plugin. Sketched, not yet designed.

## 3. P2 — Worth doing

- **#7 — A package feed.** Local `make package` is fine for the first stages
  and painful by stage 8. Not urgent, but it changes how the plugin is tested
  once there is something to test.
- **#8 — Lift the netbird gate runner across.** `tests/gates/run.sh` there
  reproduces the upstream lint and style checks without needing FreeBSD and a
  core checkout. Solved once already; re-solving it would be waste.
- **#9 — Record fixtures for every core API on first contact.** Cheap while
  writing the call, expensive to reconstruct later, and the only way the
  derivation layer is testable without a router.
- **#10 — Track upstream `Mk/` changes.** The cost accepted in §4.1. Needs to
  be a recurring check, not a surprise during a release.
- **#11 — Decide what happens to unattributable traffic** before the first
  chart is drawn. Silently dropping it makes totals disagree with core's own
  Insight page, and that discrepancy destroys trust in every other number.

## 4. P3 — Someday / nice-to-have

Everything in [DESIGN.md §2b](DESIGN.md) — the Sankey flow view, the geo map,
the time-travel slider, reputation badges, the printable weekly report, the
device comparison, the onboarding wizard, achievements, ambient sound. They are
parked, not forgotten, and each has a feasibility note from 2026-08-29 attached
so the assessment does not have to be redone.

Also here:

- **#12 — GeoIP.** Gates the map. Needs a MaxMind key belonging to the operator
  plus periodic downloads. Worth doing eventually; a hard external dependency to
  take on early.
- **#13 — "Allow this domain for 5 minutes".** Wanted, and it crosses the
  read-only line (§4.9). Deferred until the DNS view has earned it.
- **#14 — Offer the identity join to core as its own pull request**, once it has
  survived six months on a real network. Small, useful, free of UI opinion —
  the one part of this plugin that has a plausible path upstream (§4.2).
