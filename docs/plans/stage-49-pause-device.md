# Stage 49 — Pause a device (Plan)

> Systems: S1, S5 · Operator's request 2026-09-27 ("Gerät pausieren, maybe
> doch, wenn es eine gut erkennbare Regel im globalen Regelwerk erstellt") ·
> Decisions: §4.74 (the exception, 2026-10-07), §4.83 (which networks are
> never paused, 2026-10-08)

## 1. The request

A button that takes one device off the internet, and gives it back.

It is a firewall rule, which CLAUDE.md rule 6 and §4.9 forbid. The operator
moved that line for this one case (§4.74): **one** floating block rule, "Lens:
paused devices", and **one** MAC alias, `lens_paused`, through core's own
models, only on a click, removed on uninstall. Nothing else.

## 2. What exists (verified on `stable/26.7` @ `a842fe48` and `stable/26.1` @ `8cc69b21`)

Identical on both branches unless said.

- **Alias model** `OPNsense\Firewall\Alias` (`//OPNsense/Firewall/Alias`, v1.0.1):
  type `mac` exists; content is newline-separated, validated by
  `AliasContentField::validatePartialMacAddr` (`/^[0-9A-F]{2}(:[0-9A-F]{2}){1,5}$/i`).
- **How a MAC alias becomes addresses:** `filter_tables.conf` gives a `mac` alias
  `<ttl>30</ttl>`; core's cron runs `update_tables.py --quick` every minute
  (`pf.inc`, `pf_cron`), which re-resolves expired aliases through
  `ArpCache` → `interfaces/list_hosts.py -n` (hostwatch, else ARP + NDP), IPv4
  and IPv6. **An address stays in the table for 12 hours after its MAC was
  last seen with it** (`_address_ttl = 43200`, kept in
  `/tmp/alias_filter_arp.cache`).
- **Filter model** `OPNsense\Firewall\Filter` (`//OPNsense/Firewall/Filter`,
  1.0.5 on 26.1, 1.0.6 on 26.7 — the difference is `received-on` and
  `max-pkt-rate`, which Lens does not set). A rule with no interface, or more
  than one, is floating: priority group 200000 (`FilterRuleField::getPriority`),
  ahead of interface groups (300000) and interfaces (400000), and MVC rules
  precede legacy rules in the same group (`sort_order` `"%d.0%06d"`). Order
  inside the group is `sequence`.
- **Apply:** a rule change is `configctl filter reload skip_alias`
  (`FilterBaseController::applyAction`); an alias change is `template reload
  OPNsense/Filter` then `filter refresh_aliases` (`AliasController::reconfigureAction`,
  which also reloads the filter first so a new table is declared).
- **Saving:** core's API controllers lock the config, change the model,
  `serializeToConfig()`, `Config::save()` — which writes a backup and a
  revision with the user and endpoint (`getRevisionContext`). Lens does the
  same with the same classes, so the change is in Firewall: Rules, in
  System: Configuration: History and in every backup like a hand-made rule.
- **A block rule does not end connections already open.** States are dropped
  with core's `configctl filter kill states --filter=<address> --label=`
  (`kill_states.py`); several address clauses are ANDed, so it is one call per
  address, each reading the whole state table (`pfctl -vvs state`).
- **Uninstall vs upgrade** (pkg, `libpkg/pkg_add.c`, `scripts.c`): an upgrade runs
  the old package's `PRE_DEINSTALL` with `PKG_UPGRADE=true` and does not run
  its `POST_DEINSTALL`. So `+PRE_DEINSTALL.post` can remove the rule and alias
  on a real uninstall and leave them alone on an upgrade. To be confirmed with
  `pkg -v` on both boxes — older pkg may not set the variable.
- **Lens already knows:** the firewall's own MACs (`is_local`, permanent ARP
  entries), every MAC a folded device stands for (`DeviceReport`, §4.61), every
  address and interface a device has held (the store), and the requester's
  address (`request->getClientAddress()`).

## 3. What gets built

1. **`Pause` (pure PHP, no framework):** the guard; the alias content after
   adding or removing a device; the alias and rule fields; which existing rule
   is Lens's; the status of every device from the alias content and the store's
   open pauses. Everything that decides, tested.
2. **The guard (§4.74, §4.83)** refuses, with the reason in words:
   a device that is the firewall (`is_local`); the device the click comes from
   (its MACs, or the address the request came from); a device that has held an
   address on a protected network — the networks ticked under Settings, or,
   while none is ticked, the network the click comes from; a click whose
   network Lens cannot tell while none is ticked; a device with no MAC.
3. **`PauseRule` (PHP, core's models):** ensure the alias (type `mac`, enabled,
   description "Lens: paused devices — managed by Services: Lens") and the rule
   (block, quick, floating on every interface, in, IPv4+IPv6, any protocol,
   source `lens_paused`, destination any, logged, description "Lens: paused
   devices", first among floating rules); set the content; save once; apply
   only what changed. Finds its rule by `source_net = lens_paused` and action
   block, so a renamed rule is still found; never re-enables a rule the
   operator disabled — it says so instead.
4. **`api/lens/pause/*`** (its own privilege, *Services: Lens: Pause devices* —
   reading reports must not include cutting someone off): `status` (GET),
   `pause` (POST mac, minutes: 0 = until resumed, else 1…10080), `resume`
   (POST mac). The browser sends a MAC and a duration; the server works out the
   device, its MACs and the guard again (§4.35's rule: the browser asks for the
   offer, not for the change). After a pause: kill the states of every address
   the table gained.
5. **Store, schema 12:** `pause(mac, macs, started, until, ended, ended_how)`.
   `collect.py pause-start`, `pause-end`, `pauses`. Open rows say when it ends;
   closed rows are history, inside retention, removed by purge and by forget.
   An open pause survives purge — it is the firewall's state, not history — and
   forget refuses a paused device ("resume it first").
6. **Expiry:** the observe duty (every 5 minutes) starts
   `scripts/lens/pause.php expire` when an open pause is due, detached. So a
   pause ends up to five minutes after its time; the button says so.
7. **Uninstall:** `+PRE_DEINSTALL.post` runs `pause.php uninstall` unless
   `PKG_UPGRADE=true`: rule first, then alias, then reload; open pauses closed
   as `uninstall`.
8. **Events (§4.63):** paused, resumed, ended on time, removed outside Lens —
   derived from the table, no new store of events.
9. **Device page:** a *Pause* button with a duration, the limits in three
   lines under it, a note while no network is protected, and when paused a
   banner with since, until and *Resume*. Settings: *Never pause devices on* —
   a tick per network. *Not in this stage:* a mark on the Devices list and the
   dashboard; Events carry every pause meanwhile.
10. **What the alias says wins.** A MAC in `lens_paused` with no open row shows
    as "paused in the alias, not by Lens"; an open row whose MAC is no longer in
    the alias is closed as `outside` at the next status read.

## 4. Load (rule 7)

- A click: one config save (a backup in `/conf/backup`), a filter reload when
  the rule is created (once), `template reload` + `refresh_aliases`, and one
  `kill_states.py` per address the device holds — each a full `pfctl -vvs
  state`. On box-2's state table this is the cost to measure.
- While the alias exists, core re-resolves it every minute: one
  `list_hosts.py` per minute — core's cost for any MAC alias. Measure it.
- Expiry: one `SELECT` per observe run; PHP starts only when a pause is due.
- Store: one row per pause. Nothing per minute.

## 5. Test strategy

PHP (`PauseTest`): every guard branch with a reason; a folded device pauses all
its MACs; adding and removing keeps other devices' MACs and never duplicates;
the rule's fields; finding Lens's rule among others, renamed or disabled;
status when the alias and the store disagree both ways; durations clamped.
Python: migration from schema 11; start/end/list; expiry selection; purge keeps
open pauses; forget refuses a paused device; prune drops old closed rows;
events from the table. Gates: ACL lint for the new endpoints, the deinstall
script under `sh -n`. The join — controller → `PauseRule` → core's models — is
proven only on a box: the round's checklist gets the click, the rule in
Firewall: Rules, the config history entry, a ping that stops and starts, and an
uninstall that leaves no rule behind.

## 6. Known limits (said on the button, not discovered)

- A MAC alias follows the MAC. A phone that rotates its private MAC is a new
  MAC; only a folded device (§4.61) takes all its MACs along.
- It stops routed traffic and traffic to the firewall, not traffic between two
  devices on the same network.
- An address the device held in the last 12 hours stays blocked while it is
  paused — if DHCP gave such an address to another device, that one is
  blocked too until the pause ends (core's ARP cache, §2).
- A new IPv6 privacy address is blocked at the next minute's refresh.
- Through a VPN Lens sees the tunnel address, not the laptop behind it: the
  "never the clicking device" guard cannot see which LAN device you sit at.
  Protect your management networks under Settings.
- An expiry runs up to five minutes late.

## 7. Decisions

- 2026-10-07 (§4.74): the exception — one rule, one alias, through core's
  models, on a click, removed on uninstall; the guards are part of it.
- 2026-10-08 (§4.83): the networks a pause never touches are ticked under
  Settings; while none is ticked, the network the click comes from is
  protected. The clicking device and the firewall are always protected.
- MAC alias rather than host alias (the plan's open question 2): a host alias
  loses the device at its next lease, which is the failure the project exists
  to prevent (edge case 2).
