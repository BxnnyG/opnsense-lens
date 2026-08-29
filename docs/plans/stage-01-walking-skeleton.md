# Stage 01 — Walking skeleton (Plan)

The first stage of a new plugin, ahead of every system in
[DESIGN.md](../DESIGN.md) except S0. It builds nothing anyone wants and proves
everything everything else depends on: that `os-lens` compiles into a package,
installs on the router, appears under `Reporting`, is reachable through the ACL,
and passes the lint and style gates. It exists because the alternative — finding
out at stage 8 that the menu placement does not work, or that a path collides
with core — costs a rewrite instead of an evening. Decisions in play: §4.1 (own
repository), §4.3 (supply the dashboard, do not rebuild it), §4.10 (the name).

## 1. The problem from the user's point of view

None, honestly. The operator gains one menu entry and one sentence.

What they gain in fact is the answer to "will this work on my box at all"
before any effort is spent on the parts that are hard to unpick. The three
things that could be wrong are all cheap to discover now and expensive later:
the `Reporting` menu may not accept a plugin's entry (no plugin in the
collection does this today — DESIGN §1.1); the build machinery copied out of
`opnsense/plugins` may not work outside that repository (§4.1 took that risk
knowingly); and a path under `src/` may collide with something core already
owns, which makes the package refuse to install *after* the previous one has
been deinstalled.

## 2. What already exists (inventory, not guesswork)

- **Menu placement.** Core `src/opnsense/mvc/app/models/OPNsense/Core/Menu/Menu.xml:15`
  declares `<Reporting order="15" cssClass="fa fa-area-chart">`.
  `OPNsense/Diagnostics/Menu/Menu.xml` merges three entries into it from a
  separate model directory — the mechanism a plugin would use. See DESIGN §1.1.
- **Plugin layout to copy from.** `security/netbird` and `security/tailscale`
  in `BxnnyG/opnsense-plugins`: `Makefile`, `pkg-descr`,
  `src/etc/inc/plugins.inc.d/<name>.inc`, and under
  `src/opnsense/mvc/app/`: `controllers/OPNsense/<Ns>/`,
  `models/OPNsense/<Ns>/{ACL/ACL.xml,Menu/Menu.xml}`,
  `views/OPNsense/<Ns>/`.
- **Build machinery.** `Mk/plugins.mk` plus `Scripts/`, `Templates/`,
  `Keywords/`, `LICENSE` from `opnsense/plugins`. `Makefile` targets already
  known to work: `package`, `upgrade`, `mount`, `umount`, `plist`, `lint`,
  `style`.
- **Gate runner.** `security/netbird/tests/gates/run.sh` reproduces the upstream
  lint and style checks without FreeBSD or a core checkout, including a bundled
  `phpcs.phar` and ruleset. Solved once; lift it rather than re-solve it
  (BACKLOG #8).
- **Nothing else.** No code in this repository. Do not look for it.

## 3. What gets built

A minimal but complete plugin, in this repository, at `net-mgmt/lens/`:

- `Makefile` — `PLUGIN_NAME=lens`, `PLUGIN_VERSION=0.1`, `PLUGIN_REVISION=1`,
  `PLUGIN_COMMENT`, including `../../Mk/plugins.mk`. No `PLUGIN_DEPENDS`: there
  is no port behind this plugin, which the themes establish as legitimate
  (DESIGN §1.8).
- `pkg-descr` with the changelog this project's releases append to.
- One MVC page under `Reporting → Lens`, its `Menu.xml`, its `ACL.xml`, and a
  controller that renders **one true sentence**: the plugin version, and which
  of the data sources in DESIGN §1 the box exposes at all. Not a preflight — a
  liveness proof. Preflight is stage 2 and does the honest, detailed version.
- Repository scaffolding: `Mk/`, `Scripts/`, `Templates/`, `Keywords/`,
  `LICENSE` copied from `opnsense/plugins` at a commit hash **recorded in
  ROADMAP operations notes**, and `tests/gates/` lifted from netbird.

**Chosen over the alternative:** copying the build machinery in, rather than
keeping Lens as a directory in the `opnsense/plugins` fork where `Mk/` maintains
itself. §4.1 records why — the fork's job is work that is going upstream, and
Lens is explicitly not. The cost of this choice, tracking `Mk/` by hand, is
BACKLOG #10 and is accepted here rather than discovered later.

## 4. Data model / interfaces

Nothing persistent. No model XML, no store, no migration — deliberately, so
that the first schema is written in stage 4 with the retention question (S14,
BACKLOG #6) answered rather than bolted on.

One read-only API action, returning the plugin version and a per-source boolean
of the form "is this endpoint reachable and does it answer". Sources probed, per
DESIGN §1: `networkinsight/getMetadata`, `unbound/overview/isEnabled`,
`diagnostics/interface/searchArp`, and whichever leases controller is present.
Each probe is bounded by a short timeout and a failure is a reported state, not
an exception — edge case 1.

## 5. Surface / output

One page. A version line, and a list of the data sources with a plain reachable
/ not-reachable mark next to each. No charts, no interpretation, no verdict —
saying what a "not reachable" means is stage 2's job, and doing it here would
duplicate the interpretation the moment stage 2 arrives (DESIGN §0).

## 6. Test strategy

- **Unit:** the source-probe result mapping, against recorded fixtures for each
  endpoint — including the failure shapes. This is the first entry in the
  fixture library that BACKLOG #9 depends on.
- **Gates:** `tests/gates/run.sh` clean before the package is built.
- **Packaging:** `make plist` inspected before anything new is added under
  `src/` — edge case 8. Nothing outside `src/` may ship; tests and fixtures live
  at the repository root.
- **Manual, and unavoidably so:** install on the router, confirm the entry
  appears under `Reporting`, confirm a user *without* the new ACL privilege
  cannot reach the page, confirm the web interface is unchanged in
  responsiveness afterwards.

## 7. Risks & the way back

| Risk | How it shows | Way back |
|---|---|---|
| A plugin cannot merge into `Reporting` | Menu entry absent or lands at the top level after install | Fall back to `Reporting` under a different parent, or `Services`. Cheap now, and finding out now is the point of this stage. |
| Build machinery does not work outside the plugins repo | `make package` fails | Compare against a build of `net/vnstat` in the fork; if unfixable, revisit §4.1 — before any code depends on the layout. |
| A path collides with something core owns | Package refuses to install, *after* deinstalling the previous one | `make plist` before adding paths. On a live box, keep a way in that does not depend on the web interface. |
| The name changes later | A rename after installation is a migration | BACKLOG #5: settle the name before this stage ships. |

Rollback is `pkg delete os-lens`. Nothing is written outside the package, no
configuration is touched, and no other subsystem is reconfigured — §4.5's
enablement powers arrive in stage 3, not here.
