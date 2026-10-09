# Stage 51 — Interfaces with the devices behind them (Plan)

> Systems: S6, S16 · Phase 2 of §4.84 (operator 2026-10-09) · BACKLOG #49

## 1. The request

Core's interface pages know the port and nothing of who is on it; Lens's
Networks page knows the devices and the traffic and nothing of the port. One
card per network should say both: is the link up, at what speed, with which
addresses, how many errors — and who is on it now, one click from the list.

## 2. What exists (verified on `stable/26.1` @ `8cc69b21` and `stable/26.7` @ `7f3c19af`)

- **State:** `configdpRun('interface list ifconfig', [null])` → `pluginctl -D`
  → `legacy_interfaces_details()`, keyed by OS device name: `flags` (contains
  `up`), `status` (`active`, `no carrier`, …), `media`, `macaddr`, `ipv4[]` /
  `ipv6[]` with `ipaddr` and `subnetbits`, `vlan.tag`. Core's
  `Interfaces/Api/OverviewController::parseIfInfo` reads it this way: status is
  `up` when the flags say so, replaced by ifconfig's own status unless that is
  `active` or `running`.
- **Counters:** `configdpRun('interface list stats', [null])` → `pluginctl -I` →
  `legacy_interface_stats()` → `ifinfo`, keyed by device: `input errors`,
  `output errors`, `collisions`, `send queue drops`, `packets received`,
  `packets transmitted`, `line rate`, `link state` — the keys core's
  Interface Statistics widget and overview read. Since boot, never reset by
  Lens.
- **Who is there:** the store's `address_observation` is keyed by the same
  OS device name; the device list filters by it from the URL
  (`?segment=<device>`, `Lens.filter`).

Identical on both branches except the overview's link-type labels, which Lens
does not use.

## 3. What gets built

- Store: `here_by_interface(since)` — distinct MACs per interface seen by the
  last observation. The `segments` duty carries it.
- `InterfaceState` (pure PHP): one interface's link — status and tone,
  media, addresses, VLAN tag, errors as a share of packets — and a health tile
  over the assigned, enabled interfaces ("all 7 up" / "IOT: no carrier").
- Networks page: each card gains a status dot, media, addresses, errors, and
  "N devices here now ›" to the filtered device list.
- Health row: an Interfaces tile beside System.

## 4. Load (§4.8)

Two configd calls on opening Networks and on the health row (`pluginctl -D`,
`pluginctl -I`), one indexed query on the store. Measured in the round.

## 5. Done when

Tests for every status and the error rule, gates clean, the round shows
Networks and health under two seconds on both boxes, the operator has seen it.
