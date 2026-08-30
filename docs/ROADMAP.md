# Lens — Roadmap

> Target picture: [VISION.md](VISION.md) · Process: [PROCESS.md](PROCESS.md) ·
> Systems: [DESIGN.md](DESIGN.md)
>
> A stage is one independently installable package revision that a human can
> click-test on the router ([PROCESS.md](PROCESS.md)).

There is no prehistory to reconstruct: this repository begins on 2026-08-29.
Its sibling `security/netbird` in `BxnnyG/opnsense-plugins` carries the process
this one inherits, and 23 stages of experience with it.

## Stages

| # | Stage | System | Status |
|---|---|---|---|
| — | Documentation scaffold | — | ✅ 2026-08-29 |
| 0 | Hand-run tools: preflight, identity observation, summary (§4.14) | S3, S2 | ✅ 2026-08-29 · running on the operator's box from 2026-08-29 |
| 1 | Walking skeleton: `os-lens`, two menu roots, a source liveness check ([plan](plans/stage-01-walking-skeleton.md)) | S0 | ✅ 2026-08-30 · installed as `os-lens-0.1_1`, page renders, 2 s to load |
| 2 | Preflight — three verdicts, capture coverage, retention, timing ([plan](plans/stage-02-preflight.md)) | S3 | ✅ 2026-08-30 · router-tested as `os-lens-0.1_3`; both menu roots confirmed |
| 4 | **Store & collector** — identity over time, and the hourly harvest ([plan](plans/stage-04-store-and-collector.md)) | S2 | ✅ 2026-08-30 · router-tested as `os-lens-0.2_3` on both boxes; 463 buckets in 1 chunk on router-01, the second box's hang gone |
| 3 | Setup wizard — switch the missing sources on, one screen each, cost stated (§4.5, §4.13) | S3 | ⏳ deferred behind stage 4 (§4.19) |
| 5 | **Identity** — devices instead of addresses, presence that cannot lie ([plan](plans/stage-05-devices.md)) | S1 | 🔨 built 2026-08-30 · gates clean, 71 PHP + 46 Python tests · **awaiting router test** |
| 6 | Names, icons and tags — the operator's own labels | S1 | ⏳ |
| 7 | Traffic attribution — flow history joined onto identity, at the time of the bucket | S4 | ⏳ |
| 8 | Client profile page | S5 | ⏳ |
| 9 | Reporting overview, first version | S6 | ⏳ |
| 10 | Dashboard widgets | S7 | ⏳ |
| 11 | DNS view | S12 | ⏳ |
| 12 | Baseline, learning period visible, then verdicts | S8 | ⏳ |
| 13 | Correlation timeline | S9 | ⏳ |
| 14 | Wallboard / kiosk | S10 | ⏳ |
| 15 | Command palette | S11 | ⏳ |
| — | Package feed — build and publish `os-lens` so updates arrive as firmware updates | S0 | ⏳ |

Stages 2 and 3 come before anything visual on purpose. As of 2026-08-29 nobody
knows what the operator's box is collecting; every week it collects nothing is
a week of history that stages 7 onwards will not have. Getting the sources on
and the collector running is worth more, right now, than any screen.

Stage 4 comes before stage 5 for the same reason, and for one more: the MAC
randomisation problem in S1 cannot be designed in the abstract. It is decided
against observations from the operator's own network, which stage 4 produces.

## Next up (order is a proposal, movable)

| # | Intent | System | Status |
|---|---|---|---|
| 0 | Run the hand-run tools, start collecting tonight | S3, S2 | ✅ 2026-08-29 |
| 0b | Read the observation summary, decide how identity is keyed (BACKLOG #3) | S1 | ✅ 2026-08-30 · MAC-keyed (§4.17), provisional, re-check 2026-09-06 |
| 1 | Prove the chain: build → install → menu → ACL → page → gates | S0 | ✅ router-tested 2026-08-30 |
| 2 | Preflight as a page, with verdicts instead of pings | S3 | 🔨 built 2026-08-30 |
| 4 | Collect, before more of the hourly detail is deleted | S2 | ✅ router-tested 2026-08-30 |
| 5 | Devices instead of addresses — the first screen core cannot draw | S1 | 🔨 built 2026-08-30 |
| — | **The five-minute observe job is not firing on either box** — see operations | S2 | ⚠️ open 2026-08-30 |
| 3 | Setup wizard — for boxes that are not this one | S3 | ⏳ |
| — | Sankey flow view, geo map, time-travel slider, reputation badges, weekly report, comparison view, achievements | idea store, DESIGN §2b | ⏳ parked, not forgotten |
| — | IDS section | S9 slot, §4.6 | ⏳ parked until Suricata runs and the operator says so |

## Operations notes

> The things you need at three in the morning. No passwords — only where they
> live.

| Thing | Where | Status |
|---|---|---|
| Plugin source | this repository, `net-mgmt/lens/` | created 2026-08-30 |
| Build machinery | `Mk/`, `Scripts/`, `Templates/`, `Keywords/` from `opnsense/plugins` @ `8e472285` — recorded in `Mk/.upstream-commit` (§4.1) | copied 2026-08-30 |
| Package name | `os-lens` | — |
| Menu entry | `Reporting → Lens` (core `Core/Menu/Menu.xml:15` is the parent) | — |
| Own store | `/var/db/lens/lens.sqlite`, mode 0600, schema v1 | created by the collector on first run |
| Collector | `/usr/local/opnsense/scripts/lens/collect.py` — `observe`, `harvest`, `prune`, `status`, `purge` | reachable as `configctl lens <duty>` |
| Retention defaults | 365 days · 500 MB disk ceiling · 900 s observation gap | in the store's `setting` table, no settings page yet |
| Delete everything | `configctl lens purge` | works and is tested; no button until there is a settings page to confirm on |
| Hand-run observation log | `/root/lens-observations.log` on the router, 200 MB ceiling | started 2026-08-29; **superseded by the collector**, stop it once stage 4 is installed |
| Hand-run preflight report | `/root/lens-preflight-<stamp>.txt` on the router | — |
| flowd aggregate databases | `/var/netflow` · raw flows `/var/log/flowd.log` | verified 2026-08-29 |
| Unbound statistics store | `/var/unbound/data/unbound.duckdb` (DuckDB) | verified 2026-08-29 |
| NetFlow config path | `//OPNsense/Netflow` in `config.xml` | verified 2026-08-29 |
| Unbound reporting flag | `//OPNsense/unboundplus/general/stats` | verified 2026-08-29 |
| Collector schedule | observe `*/5` · harvest `*/30` · prune `04:17` | `lens_cron()` in `plugins.inc.d/lens.inc` |
| Core flow data | flowd + `flowd_aggregate` | **ON since 2026-08-23**, `collect.enable=1`, 66 MB in `/var/netflow` |
| NetFlow capture interfaces | all eleven · `egress_only=wan` | fixed 2026-08-30; flowd + flowd_aggregate both running |
| Per-client volume depth | 5 min → 1 h · hourly → 1 day · daily → 1 year | fixed in core, DESIGN §1.4 — drives S2's harvest duty |
| Per-client detail depth | **daily only, 62 days** — no sub-daily resolution exists | corrected 2026-08-30 against live metadata |
| Identity verdict | MAC-keyed (§4.17) · 13 MACs, 2 randomised, 0 address reuse in 10 h | provisional — **re-read the log 2026-09-06** |
| Devices that cannot name themselves | 6 of 13 — 3 static hosts with no lease, 3 leasing `wlan0`/`*` | BACKLOG #15 |
| Core DNS data | dnsmasq — **no query statistics exist** | Unbound config says enabled but is not the resolver (§4.15) |
| DHCP server in use | **dnsmasq**, leases at `/var/db/dnsmasq.leases` | confirmed 2026-08-30; Kea/BIND a possible future |
| Hardware | Xeon E3-1220 v5, 2 vCPU, 4 GB RAM, 23 GB disk (13 GB free) — a Proxmox guest | confirmed 2026-08-30 |
| Target release | OPNsense `stable/26.7` | operator's box: **26.7.1_1 amd64**, confirmed 2026-08-30 |
| Operator's segments | 8 VLANs on `vtnet1` (MGNT 10, IPMI 12, HOME 20, IOT 21, GUEST 22, SERVER 30, NAS 33, LAB 40) + `wt0` NetBird | confirmed 2026-08-30 |
| WAN | `pppoe0` (PPPoE) · separate mgmt NIC `vtnet2` | `capture/egress_only` must be `pppoe0` |
| IPv6 | present on HOME, MGNT and WAN — dual stack is the norm here, not the exception | confirmed 2026-08-30 |
| Root shell on the box | **`csh`** — no `$(...)`, no `2>&1` | scripts must self-redirect |
| Build & install | on the router: `make package` / `make upgrade` in the plugin directory | — |
| Live-test without installing | on the router: `make mount` … `make umount` | — |
| Measured collector cost | `observe` **7 ms** · `harvest` **459–653 ms** (7291 buckets over 21 h) | router-01, 2026-08-30 |
| Second box | OPNsense **26.1.9**, 19 interfaces incl. `lagg0` VLANs, `wg0`, `wg6`, `wt0` | the first harvest there hung; chunking added in `0.2_3` |
| Building on a box | `rsync` without `.git` makes `Scripts/version.sh` warn and leaves `product_hash` empty | harmless; copy `.git` too, or ignore it |
| Measured store growth | **0.4 MB** after one day → roughly **130 MB a year** at the 365 day default | comfortably under the 500 MB ceiling, and now measured rather than guessed |
| Measured page load cost | 0.1_1: **~2 s**, five calls · 0.1_2: **550–600 ms**, seven calls | the 1.4 s was `unbound qstats totals`, removed for a different reason |
| Slowest calls | `unbound status` 149–187 ms · `interface list arp json` 1–123 ms (30 s configd cache) · `netflow aggregate metadata` 63–231 ms | measured on the box 2026-08-30 |
| Retention defaults | — | to be set at stage 4 (S14) |

### The observe job is not firing (open, 2026-08-30)

**Evidence, not suspicion.** On router-01, `lens status` at 1788095496 reported
`runs.observe.at = 1788079090` — 4.6 hours earlier — while
`plugins.inc.d/lens.inc` schedules `configctl -d lens observe` at `*/5`. On the
second box `runs.observe` is absent entirely: the duty has never run there, on a
box where the package has been installed and configd restarted. No failed run
was recorded on either, so the script is not being started at all rather than
starting and failing.

The hook itself matches the only working precedent in the plugin collection,
`security/q-feeds-connector`'s `qfeeds.inc:32`, line for line. So the shape is
right and the question is whether the crontab was regenerated when the package
was installed. That is a fact on the box, not a thing to reason about:

    grep -n lens /var/cron/tabs/root
    grep -i lens /var/log/configd/latest.log | tail -20

An entry present and no log lines means cron is not running the job. No entry
means `rc.configure_plugins POST_INSTALL` does not regenerate the crontab, and
the fix belongs in the install path, not in the hook.

Until it is settled the two duties can be run by hand, and stage 5's device page
states in words how long ago the last observation was, rather than presenting
stale data as current (§4.22).

### What the boxes reported on `os-lens-0.2_3` (2026-08-30)

| | router-01 (26.7) | second box (26.1.9) |
|---|---|---|
| harvest | `463 buckets offered, 463 new, in 1 chunks`, 76 ms | up to date, nothing due |
| traffic rows | 7754 over 1.0 days | 53475 over 0.9 days |
| interfaces captured | 11 of 11 | 18 of 19 — GPON is not captured |
| devices observed | 13, two randomised | 0 — observe has never run there |
| resolver | dnsmasq; Unbound not used (§4.20) | Unbound, recording |
| DHCP | dnsmasq, 9 leases | none running |

The second box is the more interesting one: 53475 rows for 0.9 days against
router-01's 7754 is not more traffic, it is nineteen interfaces' worth of remote
peers (DESIGN §1.4, the double write). It is also why the harvest had to be
chunked.

**A harvest with nothing to do now says so.** `0 buckets offered, 0 new, in 0
chunks` was reported on the second box and is correct — no whole hour had closed
since the last stored bucket — but it is the same sentence a request that came
back empty would print, which is the failure mode `0.2_3` was released to fix.
It now reads `already up to date; no complete hour since the last bucket`.
