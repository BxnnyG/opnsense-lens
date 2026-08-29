# Lens — rules for AI agents

**Before any change: read and follow [docs/PROCESS.md](docs/PROCESS.md).**
No feature without an entry in [docs/DESIGN.md](docs/DESIGN.md).

Lens is an OPNsense plugin that gives every device on the network an identity
that survives an address change, and hangs everything OPNsense knows off it.
It lives in its own repository and is not going upstream (§4.1, §4.2).

## Non-negotiable

1. **Plan before building.** A plan in `docs/plans/stage-NN-<slug>.md` exists
   before the first line of code. Ten lines is a valid plan; zero is not.
2. **Inventory instead of guessing.** Core endpoints, flowd aggregation fields
   and plugin mechanics are written down in [docs/DESIGN.md §1](docs/DESIGN.md).
   Never guess one — verify against `opnsense/core` on the branch the box runs.
   Every plugin bug in this ecosystem is a boundary bug.
3. **Centralisation check.** Does this need more than one place? Then it belongs
   in the identity service (S1) or the derivation layer (S4/S8) — never in a
   Volt template, never in a widget. Interpretation in PHP or Python, layout in
   JavaScript.
4. **Open decisions go to the operator**, not into your own assumption. They are
   recorded in [docs/DESIGN.md §4](docs/DESIGN.md) with a number and a date.
5. **Do not fix past the report.** Reproduce first — here that means capturing
   the API response or the store rows that produced the symptom, as a fixture.
   If it cannot be reproduced, prove that instead of editing a correct file.
6. **Read-only, with one named exception.** Lens reads core's data and writes
   its own store. It may switch on the data sources it needs (§4.5) — never on
   install, never silently, always reversibly. It never writes a firewall rule,
   a route, an alias or a DNS policy (§4.9).
7. **The box must stay a firewall.** State the load cost of every new query
   (§4.8), measure it on real hardware, and check after every install that the
   web interface is still responsive and the collector is not running hot. A
   reporting plugin that degrades the router has negative value.
8. **Identity is not stable and never assume it is.** MAC randomisation, IPv6
   privacy addresses and DHCP reuse mean one device looks like many and one
   address was several devices. Never attribute historical traffic using the
   current ARP table. See [docs/PROCESS.md](docs/PROCESS.md) edge case 2 — it is
   the failure mode that is invisible until every chart is wrong.
9. **Everything stored here describes a real person.** Retention, per-source
   opt-in and a working purge are part of the stage that adds the field, not a
   later cleanup (S14).

## Map

| I want to … | File |
|---|---|
| know where the project is going | `docs/VISION.md` |
| know what already exists — in core and here | `docs/DESIGN.md` |
| know how I work | `docs/PROCESS.md` |
| know what comes next | `docs/ROADMAP.md` |
| know what is worth doing | `docs/BACKLOG.md` |
| know the operational details | `docs/ROADMAP.md` (bottom) |

## Commands

| Purpose | Command |
|---|---|
| Open issues | `gh issue list --state open` |
| Lint / style | `tests/gates/run.sh` (lifted from `security/netbird`, stage 1) |
| Build & install | on the router: `make package` / `make upgrade` in `net-mgmt/lens` |
| Live-test without installing | on the router: `make mount` … `make umount` |
| Check what ships | `make plist` — everything under `src/` installs into `/usr/local` |
| Release bookkeeping | bump `PLUGIN_REVISION` in the `Makefile`, add a changelog line to `pkg-descr`, update `ROADMAP` status and `DESIGN §1b` |

Commit subjects start with `lens: `.

Sibling project sharing this process: `security/netbird` in
`BxnnyG/opnsense-plugins`, 23 stages of experience with it.
