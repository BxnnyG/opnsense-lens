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
