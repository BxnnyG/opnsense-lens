# Stage 52 — Tiles for what is installed (Plan)

> Systems: S16, S10 · Phase 3 of §4.84 (operator 2026-10-09) · BACKLOG #50

## 1. The request

The health row gets a tile for each thing the operator runs that can go wrong
quietly — and none for what is not installed (§4.84). This stage: DynDNS,
SMART, WireGuard. OpenVPN, IPsec and CARP follow on the same pattern.

## 2. What exists

Plugins read from `opnsense/plugins` at the versions box-2 runs (os-ddclient
1.31_1, os-smart 2.4); WireGuard from core on `stable/26.1` @ `8cc69b21` and
`stable/26.7` @ `7f3c19af`, identical on both.

- **DynDNS** (`dns/ddclient`): accounts at `//OPNsense/DynDNS/accounts/account`
  (`enabled`, `description`, `hostnames`, uuid attribute). What each name
  points at now: `configctl ddclient statistics` → `{hosts: {...}}`, keyed by
  the account's uuid (the native backend) or by hostname (ddclient), each with
  `ip` and `mtime` — `AccountField::addStatsFields` maps it this way. Privilege
  `api/dyndns/accounts/*`, page `/ui/dyndns`.
- **SMART** (`sysutils/smart`): `configctl smart detailed list` →
  `[{device, ident, state: {smart_status: {passed}}}]`, as the plugin's own
  widget reads it. It runs `smartctl -jH` per disk. Privilege
  `api/smart/service/*`, page `/ui/smart`.
- **WireGuard** (core): `configctl wireguard show` → `records[]` with `type`
  (`interface` / `peer`), `if`, `public-key`, `latest-handshake`; core calls a
  peer online within 300 s of its last handshake
  (`Wireguard/Api/ServiceController::showAction`). Names: config
  `//OPNsense/wireguard/client/clients/client` (`name`, `pubkey`) — read from
  config.xml, never the Server model, which carries private keys. Privilege
  `api/wireguard/service/*`, page `/ui/wireguard/diagnostics`.
- **Installed?** The plugin's configd actions file exists
  (`/usr/local/opnsense/service/conf/actions.d/actions_ddclient.conf`,
  `actions_smart.conf`); WireGuard counts when an instance is configured.

## 3. What gets built

- `Health::dyndns(accounts, statistics, public)`: every enabled name points at
  the public address (§4.81) — good; one points elsewhere — bad, with both
  addresses; no public address known — grey with what each points at.
- `Health::smart(disks)`: every disk passes — good; one fails — bad.
- `Health::wireguard(records, names)`: who is connected now, by name — an
  absent road-warrior is no fault, so the tone is good unless nothing can be
  read; the tile says who, not whether.
- In `healthAction`, each only when installed and when the user may open the
  plugin's own endpoint.

## 4. Load (§4.8)

Up to three configd calls more on the dashboard; `smartctl -H` may wake a
sleeping disk — said here, measured in the round.

## 5. Done when

Tests for every tone, gates clean, the round on box-2 (which runs both
plugins) under two seconds.
