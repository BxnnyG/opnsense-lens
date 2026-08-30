# Stage 05 — Devices (Plan)

> Systems: S1 · Depends on: stage 4 (the store) · Built 2026-08-30
>
> Entry rule (PROCESS): every item below traces to DESIGN §S1, §4.17, §4.22 or
> §4.23. Nothing is built here that is not in one of them.

## 1. The problem from the user's point of view

You open Reporting and OPNsense tells you `10.0.10.5` used 4 GB. You do not own
a `10.0.10.5`. You own a laptop, a Pi, two phones and a printer, and by the time
you go looking, the address belongs to something else.

Every screen in OPNsense that names a device names it in the present tense: the
ARP table shows who holds an address *right now*, the lease list shows what DHCP
handed out *right now*. Nothing keeps the answer. Stage 4 started keeping it.
This stage is the first screen that shows it back.

After this stage the Reporting page opens with a list of the devices on your
network — the name each one announces, who made it, which addresses it holds and
on which segments, whether it is here at this moment, and how long Lens has
known it. That is the sentence core cannot produce.

## 2. What already exists (inventory, not guesswork)

| Thing | Where | Verified |
|---|---|---|
| `device` and `address_observation` tables | `lenslib/store.py`, schema v2 | 2026-08-30, both boxes |
| observations on a real network | router-01: 13 devices, 25 windows | 2026-08-30 |
| MAC vendor database | `configctl interface list macdb`, keyed `strtoupper(substr(mac, 0, 6))` | core's own `DHCPv4/Api/LeasesController.php:145` |
| the observe run's timestamp | `lens status` → `runs.observe.at` | 2026-08-30 |
| a page to put it on | `Reporting: Lens`, whose opening line has been a placeholder since stage 2 | 2026-08-30 |

## 3. What gets built

1. `Store.devices()` — every device with every window it has held. A list of
   addresses per device, never a current address (S1: the admin PC is in two
   VLANs at once).
2. `collect.py devices` and the configd action `lens devices`. A **read**: it
   writes no `run_log` row, because opening a page is not an event in the
   collector's history and must not look like one.
3. `DeviceReport` — pure. Observations plus the vendor table plus the
   observation timestamp, in; display rows, out. It owns three decisions:
   naming (§S1), presence (§4.22) and the vendor fallback (§4.23).
4. `Duration` — ages in words, shared. Written because the store page shipped
   `16477 seconds ago` to a human being.
5. `Api/DevicesController` — three reads, no interpretation (§4.21).
6. The device list at the top of `overview.volt`, on its own request so a slow
   source probe cannot delay it.

## 4. What is deliberately *not* in this stage

- **Operator-chosen names, icons and tags.** That is stage 6, and it changes the
  data model (owned fields beside observed ones). Naming here is observed only.
- **Traffic per device.** Stage 7. The buckets are being kept; joining them onto
  identity *at the time of the bucket* is its own problem.
- **Manual merge of randomised MACs.** §4.17 is provisional until the
  observation log is re-read on 2026-09-06. Until then randomised MACs are
  labelled, not merged — a label can be withdrawn, a merge cannot.

## 5. Test strategy

| Failure mode | Test |
|---|---|
| the admin PC listed twice, at half its size | `testADeviceInTwoVlansAtOnceIsOneDeviceWithTwoAddresses` |
| a dead collector rendered as an empty network | `testWhenTheCollectorIsStaleNoDeviceClaimsToBeHereNow`, `testAStaleCollectorIsSaidOutLoudAndNotShownAsAnEmptyNetwork` |
| "no devices" when the truth is "never looked" | `testACollectorThatNeverRanSaysSoInsteadOfReportingNoDevices` |
| a guessed name for an unknown vendor | `testAnUnknownVendorIsNotGuessedAtAndTheMacIsShownInstead` |
| a randomised MAC listed as if it were stable | `testARandomisedMacIsLabelledRatherThanQuietlyListed` |
| the store/CLI join (PROCESS: a change over two files gets a joined test) | `test_devices_is_json_the_web_side_can_decode_and_writes_no_run_row` |

## 6. Risks & the way back

- **The list is only as good as the collector.** On both of the operator's boxes
  the five-minute observe job appears not to be firing (ROADMAP, operations).
  This stage makes that visible rather than hiding it, which is the right order:
  the page now states the staleness in words. Fixing the schedule is separate.
- **A page that shows nothing on a fresh install.** Accepted and stated: the
  banner names the command that fills it.
- **Way back:** the whole stage is additive. Removing the block from the view
  restores the previous page exactly; the store gains no column.
