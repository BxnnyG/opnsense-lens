# Lens — Working process

> Binding for EVERY change. No exception for "small" features — the small ones
> are what tear the holes.
> Target picture: [VISION.md](VISION.md) · Systems: [DESIGN.md](DESIGN.md)

## 0. Check the tracker (at the start of EVERY session)

```
gh issue list --state open
gh issue list --state closed --limit 20
```

Lens has its own repository (§4.1), so its issues are its own. There is no
upstream tracker to watch — but a bug that turns out to be a *core* bug is
reported to `opnsense/core`, and the issue here links to it rather than working
around it silently.

- Small, clear bug → fix immediately, own commit, `Fixes #<n>`, plus whatever
  proof is possible.
- Bigger → plan it as a stage.
- **Do not fix past the report.** Reproduce first. For this plugin "reproduce"
  usually means capturing the API response or the store rows that produced the
  symptom, and saving them as a fixture. If it cannot be reproduced, document
  that instead of blindly editing a file that is correct.

## 1. Plan (what & why)

- What is the problem **from the operator's point of view**? Which of the three
  people in [VISION.md](VISION.md) has it? If the answer is "person 2 or 3",
  check whether the assumption marked on them still holds.
- **Centralisation check:** does this need more than one place? Then it belongs
  in the identity service (S1) or the derivation layer (S4/S8), never in a Volt
  template and never in a widget (§4.3, DESIGN §0).
- What already exists? Check [DESIGN.md §1](DESIGN.md) — the inventory of core
  APIs, aggregation fields and plugin mechanics. **Do not guess a core endpoint
  or a flowd field name.** Verify it against `opnsense/core` on the release
  branch the box runs. Every plugin bug in this ecosystem's history is a
  boundary bug.
- Walk the edge-case list below. Every time.
- **State the load cost** of anything new (§4.8): which query, how much data, at
  what interval.
- Open decisions go to the operator, not into a guess. They land in
  [DESIGN.md §4](DESIGN.md) with a number and a date.

### Edge-case checklist (walk it every time)

0. **Would this warning ever go green?** Before any amber or red state is
   added, name what the operator would do about it. If the honest answer is
   "nothing, that is just how my network is", the state is grey and factual, not
   a warning (§4.20). A warning that cannot be resolved teaches the reader to
   skip the row, and then the page.
1. **No data, or the source is switched off.** A fresh install has an empty
   store, and NetFlow or Unbound reporting may never have been enabled. Every
   surface renders an explicit state — "collecting since 27 August", "NetFlow is
   off, here is what that costs you" — never a blank panel and never a flat line
   at zero pretending to be a measurement.
2. **Identity is not stable, and that is normal.** MAC randomisation rotates a
   phone's address per network and over time; IPv6 privacy extensions rotate
   addresses hourly; DHCP hands the same address to a different device next
   week; static hosts never appear in a lease at all (three of thirteen here).
   One device can look like many, and one address can have been several devices.
   Nothing may attribute historical traffic using the *current* ARP table. Ask,
   for every change: what does this do to a phone that changed its MAC
   yesterday?
   **And the mirror of it: one device legitimately holds several addresses at
   once.** An admin PC in two VLANs, a server with a management interface, the
   firewall across eight. That is not churn and must never be counted as such —
   nor may such a device be split into two half-sized entries in any list, chart
   or total (§4.17, S4).
3. **Load budget (§4.8, S13).** No unbounded scan on a page load. Every list
   paginated server-side, every time range bounded, every collector run
   measured. State the cost in the plan; measure it on the operator's real
   hardware before calling the stage done.
4. **Disk and retention.** flowd's database plus ours share a small disk that
   also holds the firewall's logs. A full disk takes the box down. Any new
   stored field has a retention, a size estimate, and a place in the purge path.
5. **Privacy (S14).** Every field stored here is behavioural data about a real
   person. Ask: is this in the retention list, is its source individually
   switchable, does purge actually remove it, and would the operator be
   comfortable if the person it describes read this screen? DNS query logging
   is opt-in with the consequence on the switch.
6. **Strings from the LAN are untrusted.** Hostnames, DHCP vendor strings, DNS
   names and mDNS names are supplied by devices — including a guest's phone and,
   in the worst case, something hostile. Escape on output; never hand a raw
   value to `.html()`; never build a query by concatenation.
7. **IPv6 is not optional.** Devices are dual-stack and their two addresses tell
   different halves of the story. Nothing keys on IPv4 alone; nothing shows a
   device's traffic while silently omitting half of it.
8. **Packaging and upgrade.** Everything under `src/` installs into
   `/usr/local`, with no way to exclude a file — tests, fixtures and notes live
   outside it; verify with `make plist` before adding a path. A model change
   means a `<version>` bump plus a migration; a store schema change means a
   migration that runs before the first read and is tested against the previous
   schema.

## 2. Implementation plan (how)

A file under `docs/plans/stage-NN-<slug>.md`, in the fixed chapter order (see
any existing plan). Ten lines is a valid plan; zero is not. A chapter that stays
empty means the thinking has not happened — not that the chapter is unnecessary.

## 3. Build

- Derivation and store logic first, with tests; then the endpoint; then the
  surface. Interpretation in PHP or Python, layout in JavaScript (DESIGN §0).
- **A controller does input and output. Nothing else.** If it decides anything —
  which key to set, which branch a value falls into — that decision has no test,
  because it cannot be reached without `Backend` and `Config`. Put it in a pure
  class instead (§4.21). This is not style: it is the shape of the one bug that
  passed the entire suite.
- **When a change touches two files, test the join.** Green unit tests on either
  side of a wiring mistake stay green.
- **Every failure mode the plan names gets a test in the same stage.** Stage 4's
  plan said a harvest that stores nothing while flowd is fresh must be reported,
  not swallowed; it was then implemented from memory and did the opposite, and
  shipped. Writing the requirement down is not implementing it.
- **Cite a precedent by its whole package, not by the file you went looking in.**
  The `_cron()` hook was checked against `q-feeds-connector`'s `.inc` line for
  line and declared correct. It was correct. The same plugin also ships a
  `+POST_INSTALL.post` whose one line is what actually writes the crontab, and
  because nothing sent me to that file I did not open it, and Lens collected
  nothing on its own for two days. `ls` the reference plugin's directory before
  claiming a shape matches.
- Small commits. Commit subjects start with `lens: `.
- Record a fixture for every external API shape the stage relies on. That is
  what makes the next change provable without a router.

## 4. Verify & ship

- Unit tests over derivation, against fixtures.
- **Click-test on the operator's OPNsense.** Judgement calls — is this page
  actually faster to read — are settled by a human on the box, not by a test.
- **Measure the load** the stage added (§4.8) and record it in the ROADMAP
  operations notes.
- **Regression check before every release: the box stays a firewall.** After
  installing, confirm the web interface is still responsive, the collector is
  not running hot, and no page takes longer than a couple of seconds. A
  reporting plugin that degrades the router is worse than no reporting plugin.
- Pull documentation along: `ROADMAP` status, `DESIGN §1b` status table, the
  changelog in `pkg-descr`, `PLUGIN_REVISION` in the `Makefile`.

**Entry rule:** no feature is built that does not already exist as an entry in
[DESIGN.md](DESIGN.md) — three lines is enough, zero is not.

## What a stage is

One independently installable package revision that a human can click-test on
the router and that leaves the plugin in a shippable state. Not "a day", not "a
feature" — the test is: could this be installed on its own and be an
improvement?

## What proves something is done

1. Fixtures + unit tests for anything derived, and
2. a click-test on the router by the operator, and
3. a recorded load measurement for anything that queries.

All three. A stage that only has (1) is "plausible", and the BACKLOG says so.
