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
| 1 | Walking skeleton: `os-lens` builds, installs, appears under `Reporting`, renders one true sentence ([plan](plans/stage-01-walking-skeleton.md)) | S0 | ⏳ |
| 2 | Preflight — report what data the box actually has, and what each missing source costs | S3 | ⏳ |
| 3 | Source setup — switch NetFlow capture, aggregation and Unbound reporting on, deliberately and reversibly (§4.5) | S3 | ⏳ |
| 4 | Store & collector — start recording identity observations, before anything displays them | S2 | ⏳ |
| 5 | Identity — devices instead of addresses, with the randomisation question answered against real observations | S1 | ⏳ |
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
| 1 | Prove the chain: build → install → menu → ACL → page → gates | S0 | ⏳ next |
| 2 | Find out what the box has. This also answers the question the operator could not on 2026-08-29. | S3 | ⏳ |
| 3 | Turn the missing sources on | S3 | ⏳ |
| 4 | Collect | S2 | ⏳ |
| — | Sankey flow view, geo map, time-travel slider, reputation badges, weekly report, comparison view, achievements | idea store, DESIGN §2b | ⏳ parked, not forgotten |
| — | IDS section | S9 slot, §4.6 | ⏳ parked until Suricata runs and the operator says so |

## Operations notes

> The things you need at three in the morning. No passwords — only where they
> live.

| Thing | Where | Status |
|---|---|---|
| Plugin source | this repository, `net-mgmt/lens/` | not created yet (stage 1) |
| Build machinery | `Mk/`, `Scripts/`, `Templates/`, `Keywords/`, copied from `opnsense/plugins` at a recorded commit (§4.1) | not copied yet (stage 1) |
| Package name | `os-lens` | — |
| Menu entry | `Reporting → Lens` (core `Core/Menu/Menu.xml:15` is the parent) | — |
| Own store | `/var/db/lens/` — SQLite, written by the collector and configd only | not created yet (stage 4) |
| Collector schedule | `_cron()` hook in `src/etc/inc/plugins.inc.d/lens.inc` | not created yet (stage 4) |
| Core flow data | flowd + `flowd_aggregate`, read via `/api/diagnostics/networkinsight/*` | state on the box **unknown as of 2026-08-29** |
| Core DNS data | Unbound reporting, read via `/api/unbound/overview/*` | state on the box **unknown as of 2026-08-29** |
| DHCP server in use | Kea or Dnsmasq — determines which leases API to read | **unknown as of 2026-08-29** |
| Target release | OPNsense `stable/26.7` | — |
| Build & install | on the router: `make package` / `make upgrade` in the plugin directory | — |
| Live-test without installing | on the router: `make mount` … `make umount` | — |
| Measured collector cost | — | to be filled at stage 4 (§4.8) |
| Measured page load cost | — | to be filled from stage 8 (§4.8) |
| Retention defaults | — | to be set at stage 4 (S14) |
