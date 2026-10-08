# Stage 49 — Pause a device (Plan, decided 2026-10-07 — §4.74)

> Systems: S1, S5 · Operator's request 2026-09-27 ("Gerät pausieren, maybe
> doch, wenn es eine gut erkennbare Regel im globalen Regelwerk erstellt") ·
> Decision: **needs the operator to amend CLAUDE.md rule 6 and §4.9 first**

## 1. The request

A button that takes one device off the internet, and gives it back.

## 2. Why this is not simply built

CLAUDE.md rule 6 and DESIGN §4.9: Lens never writes a firewall rule, a route,
an alias or a DNS policy. A pause is exactly a firewall rule. This is the one
line in the project an agent may not move on its own; it is the operator's.

## 3. What it would be, if the operator lifts the line for this one case

- One floating block rule, created once, clearly named ("Lens: paused
  devices", description pointing back to Lens), quick, on all interfaces,
  source = one alias `lens_paused` (type MAC — core supports MAC aliases,
  resolved from the ARP/NDP table).
- Pausing adds the device's MACs to the alias; resuming removes them; the
  rule stays, empty. Everything through core's own API
  (`firewall/alias`, `firewall/filter` + `apply`), so it shows in the GUI,
  the config history and the backup like any hand-made rule.
- Guards: never the firewall's own MACs, never the device the request comes
  from, never a device on the management network; every pause and resume is
  an Event; an optional end time.
- Uninstall removes the rule and the alias.

## 4. Known limits (to be said on the button, not discovered)

A MAC alias follows the ARP table: a phone that rotates its private MAC is a
new MAC, and only a folded device (§4.61) takes all its MACs along. It stops
routed traffic, not traffic between two devices on the same network.

## 5. What the operator decides

1. Amend rule 6 / §4.9 with this one named exception — yes or no.
2. MAC alias (follows the device across addresses) or host alias (simpler,
   loses it on a new lease)?


### Found 2026-10-08 ([USER-NEEDS.md](../USER-NEEDS.md) §2.3, BACKLOG #50)

- **pf keeps established states.** The rule stops new connections; a game or a
  stream already running carries on until its states expire. After the alias
  applies, kill the states of every address the device holds now through
  core's `POST /api/diagnostics/firewall/kill_states` (filter; `stable/26.7`,
  `FirewallController::killStatesAction`). Ask the operator whether §4.74
  covers it.
- **The alias is resolved, not live.** Core turns the MACs into addresses from
  hostwatch (on by default since 25.7.11) or ARP. Measure click-to-effect on
  both boxes and say it on the button.
- A recurring schedule is **not** covered by §4.74 (no click at the moment of
  the change). The optional end time is.

## 6. Decided (2026-10-07)

The operator said yes. CLAUDE.md rule 6 now names this exception and §4.74
records it, including what it does not license. Built once the router round
passes.
