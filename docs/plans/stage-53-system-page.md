# Stage 53 — Stay in Lens: the System page (Plan)

> Systems: S6, S16 · Phase 4 of §4.84 · Operator 2026-10-09: "bei Temperatur
> leitet mich die Seite weiter von Reporting: Lens zu anderen Seiten … bei Lens
> bleiben und besser machen" · BACKLOG #51

## 1. The request

A tile is a question; clicking it should answer it in Lens, not drop the
operator on a core page about something else (temperature led to System:
Health's packet graphs). Core's page stays one link away, inside the answer,
for when something has to be changed there.

## 2. What exists (verified on `stable/26.1` @ `8cc69b21` and `stable/26.7` @ `7f3c19af`)

- **History:** `configctl health list` (`listReports.py`) → every
  `/var/db/rrd/*.rrd` with `topic`, `itemName`, `filename` and the definition's
  `title`, `y-axis_label`, `field_units`; `system-processor` → topic `system`,
  item `processor`. `configdpRun('health fetch', [filename])` (`fetchData.py`)
  → `{step, lastupdate, sets: [{step_size, recorded_time, ds: [{key, values:
  [[ms, value|null]]}]}]}`, one set per AVERAGE archive — what
  `SystemhealthController::getSystemHealthAction` reads. Identical script on
  both branches. Which files a box has is the round's question (`ls
  /var/db/rrd`, added).
- **Everything else** the health row already reads (stages 50–52).

## 3. What gets built

- `SystemHistory` (pure): the archive whose step is finest while still
  covering the range, cut to the range, timestamps in seconds.
- `api/lens/system/history?hours=`: processor, memory, and every RRD whose
  name says temperature, each with its title and units.
- `api/lens/system/details`: the lists behind each tile — every service with
  its state, every certificate with expiry and users, the waiting updates,
  the disks, the WireGuard peers, the DynDNS names — under the same core
  privileges as the tiles.
- Reporting: Lens: System (`/ui/lens/system`): the health row on top, then one
  section per tile with its list or chart, and "Open in OPNsense ›" inside it.
- The tiles link to their section there (`#temperature`); internet stays on
  the dashboard, interfaces on Networks.

## 4. Load (§4.8)

`rrdtool dump` per file, on opening the page only; three files at most.
Measured in the round.

## 5. Done when

Tests for the archive choice; gates (ACL covers the new page and endpoints);
the round on both boxes; the operator clicks temperature and stays in Lens.
