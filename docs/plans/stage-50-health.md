# Stage 50 — Is everything all right? (Plan)

> Systems: S3, S10, S16 · Operator's request 2026-10-09 ("das OPNsense-Dashboard
> ist zu bloated … alles mit Lens machen können … interfaces, status, IPs,
> thermals, services, sys infos, memory, disk, dyn dns, gateways, certificates,
> smart …") · Decision: §4.84 (Lens as the calm front page, 2026-10-09)

## 1. The request, and what it is not

The operator wants one page that answers "is my firewall all right?" — not a
second copy of core's widgets. §4.84 sets the shape: a sentence first, one tile
per area with a tone and a reason, a link to the core page that fixes it. Lens
reads what core already measures and keeps none of it.

This stage is phase 1 of §4.84: the **health row** on Lens's dashboard and the
headline that takes it into account. Interfaces with their devices (phase 2),
plugin tiles — DynDNS, SMART, VPN (phase 3) — and core's RRD history (phase 4)
are their own stages.

## 2. What exists (verified on `stable/26.1` @ `8cc69b21` and `stable/26.7` @ `7f3c19af`)

Identical on both branches unless said.

- **Firmware:** `configctl firmware product` (JSON), read-only; core's
  `FirmwareController::statusAction` reads the same and only runs `firmware
  probe` on POST. `product_check` is null before the first check. Updates are
  the packages in `new_/reinstall_/upgrade_/downgrade_/remove_packages`, else
  `upgrade_sets`; `connection` / `repository` other than `ok` is a failed check;
  `needs_reboot` / `upgrade_needs_reboot` say a reboot follows.
- **Services:** `configctl service list` (JSON list: `name`, `description`,
  `status` containing "is running", optional `id`, `locked`, `nocheck`), read
  by `Core/Api/ServiceController::searchAction` the same way.
- **Temperatures:** `configctl system sensors` (one sysctl name per line), then
  `configdpRun('system sysctl values', [names])` — `"52.0C"` per name; as
  `Diagnostics/Api/SystemController::systemTemperatureAction` does. Empty on a
  VM.
- **Certificates:** `//cert` in config.xml, `crt` base64 PEM. Core's
  `CertificatesField` derives `valid_to` with `Store::parseX509`; Lens reads
  only `crt` and parses it with `openssl_x509_parse` — **never** the model,
  which also loads every private key. "In use" as core decides it: the refid
  appears somewhere in config.xml other than under `cert` or `system.user`.
- **CPU, memory, disk, uptime:** SystemFacts (stage 42) already reads them.
- **Privileges:** `OPNsense\Core\ACL::isPageAccessible($user, $url)` (URL with
  a leading slash, `urlMatch` anchors on it), `ControllerRoot::getUserName()`.
  The tiles core guards are shown only to a user who may open core's own
  endpoint for them: `/api/core/firmware/status`, `/api/core/service/search`,
  `/api/trust/cert/search`.
- **Links:** `/ui/diagnostics/systemhealth`, `/ui/core/firmware#status`,
  `/ui/core/service`, `/ui/trust/cert` — from core's `Menu.xml` on both
  branches.

## 3. What gets built

- `Health` (pure, PHP): one function per area, each turning core's answer
  into a tile `{key, title, icon, tone: good|warn|bad|grey, sentence, detail,
  link}`, and `summary(tiles)` — the worst tone and the sentence that leads.
  Thresholds: disk 85/95 %, memory 90 %, load over 1.5× the cores for fifteen
  minutes, temperature 80/90 °C, certificates in use 14/3 days or expired.
- `DashboardController::healthAction` (`/api/lens/dashboard/health`, inside
  the dashboard's existing ACL pattern): each source read on its own, timed, a
  failure greys one tile. System, temperature and internet stand under Lens's
  own privilege, as the system card always has (§4.38); updates, services and
  certificates only for a user who may open core's own endpoint — left out,
  not greyed.
- Dashboard: the row above everything, the headline saying "all is well" only
  when every tile is good.
- Round: `/api/lens/dashboard/health` in the endpoint list.
- Preview: stand-ins for `ACL`, `getUserName`, `gettext`, and fixtures for the
  firmware check, the service list, two sensors and one certificate in use.

## 4. Load (§4.8)

Five configd calls on opening the dashboard (firmware product, service list,
sensors, sysctl, plus the existing system call) and one config read. No
writes, no probes, no store. Measured on both boxes in the round.

## 5. Done when

Tests for every tone of every tile; gates clean; the round shows the
endpoint under two seconds on both boxes; the operator has seen the row.
