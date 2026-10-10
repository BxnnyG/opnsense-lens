# Stage 59 — OpenVPN, IPsec and CARP tiles (Plan)

> Systems: S16 · The rest of BACKLOG #50 (stage 52 built DynDNS, SMART,
> WireGuard) · Phase 3 of §4.84 · Operator: "weiter"

## 1. The request

A tile for each thing the operator runs that can go wrong quietly, and none
for what is not configured (§4.84). OpenVPN, IPsec and CARP were left open.

## 2. What exists (verified on `stable/26.1` @ `8cc69b2` and `stable/26.7` @ `ea4f01f`)

- **OpenVPN:** `configctl openvpn connections client,server`
  (`ovpn_status.py`) → `{server: {id: {status, client_list[]}}, client: {id:
  {status, virtual_address, real_address}}}`, one entry per management socket
  in `/var/etc/openvpn`. `id` is the legacy `vpnid` or the instance's uuid, as
  `OpenVPN/Api/ServiceController::getConfigs()` indexes the configuration
  (legacy `//openvpn/openvpn-{server,client}` with `disable`, current
  `//OPNsense/OpenVPN/Instances/Instance` with `enabled`, `role`). A client's
  status is its management `state`: `connected`, or `connecting`,
  `reconnecting`, `wait`, `auth` …; a socket that does not answer is
  `failed`. Privilege `api/openvpn/service/*` (Status: OpenVPN), page
  `/ui/openvpn/status`. On 26.1 the action lacks `allowed_groups`, which
  does not concern a call from the web GUI.
- **IPsec:** `configctl ipsec list status` (`list_status.py`, vici) → one
  entry per connection with `sas[]`, or the plain text `ipsec not active`
  when strongSwan does not answer. Core's sessions page calls a connection
  connected when `sas` is not empty, and maps a name to its configuration as
  the uuid or `con<ikeid>-…`. Switched on: `//ipsec/enable`. A current
  connection's children carry `start_action` (`start`, `trap|start`, `trap`,
  `route`, `none`). Privilege `api/ipsec/sessions/*`, page `/ui/ipsec/sessions`.
- **CARP:** `interface list ifconfig` — already read for the Interfaces tile
  — carries `carp: {vhid: {status, advbase, advskew}}` per device and a
  `vhid` on each address that belongs to one; core's
  `Diagnostics/Api/InterfaceController::getVipStatusAction` reads it this way
  and calls a configured VHID the kernel does not list `DISABLED`.
  Configuration `//virtualip/vip` with `mode` `carp`. Privilege
  `api/diagnostics/interface/get_vip_status` (Status: CARP), page
  `/ui/diagnostics/interface/vip`.

## 3. The rules

- **OpenVPN:** every enabled instance answers its socket — else *not running*,
  bad. A client instance is through to its server — else its state, worth a
  look. A server nobody is connected to is no fault; its clients are listed.
- **IPsec:** switched on but strongSwan not answering — bad. A connection is
  *expected* up when its far end is a real address and something starts it
  (`start` in a child's `start_action`; a legacy phase 1 that is not mobile).
  Expected and without an SA — worth a look. Road warriors and tunnels that
  come up on traffic are counted, never judged.
- **CARP:** `INIT` — bad (it neither hears nor claims). `DISABLED` — worth a
  look. Master for some and backup for others — worth a look: the two
  firewalls split the work, which is rarely meant. All master or all backup —
  good, and the tile says which.

## 4. What gets built

- `Tunnels` (pure): configured-from-config and rows-from-status for each; a
  row is name, address, tone, state — the System page lists the same rows the
  tile is judged on.
- `Health::openvpn / ipsec / carp`, judged on the rows.
- Health row and System page: each only where configured and where the user
  may open core's status page for it.
- The round prints the shape of the three answers: states and counts, no
  addresses or names.

## 5. Load (§4.8)

Up to two configd calls more, asked with the rest at once (stage 58), and
only where configured; CARP reads the ifconfig already asked.

## 6. Done when

Tests for every rule above; gates clean; the preview shows a road-warrior
server and two master VHIDs; the round's shape on a box with any of the
three.
