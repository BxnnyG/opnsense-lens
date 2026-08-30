# Stage 07 — Traffic attribution (Plan)

> Systems: S4 · Depends on: stages 4 and 5 · Built 2026-08-30
>
> Entry rule (PROCESS): every item traces to DESIGN §S4, §1.4 or §4.26.

## 1. The problem from the user's point of view

Reporting → Insight tells you `10.0.10.5` used 4 GB. Etappe 5 told you who
`10.0.10.5` is *today*. Neither tells you who used the 4 GB, because the address
may have changed hands since — and after twenty-four hours OPNsense has deleted
the hourly detail anyway, leaving one number per day and no way back.

After this stage the device list carries a Traffic column, and the number in it
belongs to whoever held the address **in the hour the traffic was measured**.
That is the sentence the plugin was built for.

## 2. What already exists (inventory)

| Thing | Where | Verified |
|---|---|---|
| hourly buckets with the interface | `traffic_hour`, schema v2 | 7754 rows router-01 / 53475 rows box 2, 2026-08-30 |
| address windows with validity | `address_observation` | 2026-08-30 |
| the double write | DESIGN §1.4 | measured: 91% of router-01's rows are `pppoe0` |
| `lo0` and the literal `'0'` interface | DESIGN §1.4 | 70 rows / 38 rows, 805 MB |
| an index on observations | v1's `(address, first_seen)` | read 2026-08-30 — the wrong columns for this join |

## 3. What gets built

1. `Store.traffic_rows(since)` — one pass over the buckets, left-joined onto the
   observations that **overlap the bucket's hour**, grouped on `traffic_hour`'s
   own primary key so the join cannot multiply octets. Returns how many distinct
   devices the overlap found, not just one of them.
2. Schema v3 — the index swapped to `(address, interface)`, which is what this
   join reads. Nothing read the old one by `first_seen`.
3. `lenslib/attribute.py` — pure. Four outcomes, never three: a device, the far
   end, an unknown holder, or an hour two devices shared.
4. `collect.py traffic --hours N` and the configd action `lens traffic <hours>`.
   A read; it writes no `run_log` row.
5. `Bytes` — octets in units a person reads.
6. `DeviceReport` gains the Traffic column, sorts by it when there is any, and
   returns the accounting of what it could not attribute.

## 4. The decision this stage rests on

**Every byte is accounted for, or the number above it is not trustworthy** — see
DESIGN §4.26. On router-01, nine of every ten harvested rows are the far end of
a flow. A top-talkers table that dropped them silently would look perfectly
plausible while hiding its own input. So the page shows what was attributed and,
beneath it, every class of byte that was not, with the reason.

## 5. Test strategy

| Failure mode | Test |
|---|---|
| traffic attributed to whoever holds the address *now* | `test_the_address_that_changed_hands_is_attributed_to_who_held_it_then` |
| a join that multiplies octets across overlapping windows | `test_the_join_cannot_multiply_the_octets_it_counts` |
| a five-minute window missing the hour it falls inside | `test_a_window_that_opened_mid_hour_still_covers_that_hour` |
| the internet counted as a device | `test_the_far_end_of_a_flow_is_never_a_device` |
| a shared hour split by guesswork | `test_an_hour_two_devices_shared_is_refused_rather_than_guessed` |
| bytes vanishing between input and display | `test_every_octet_lands_somewhere`, `testWhatCouldNotBeAttributedIsShownRatherThanDropped` |
| the upgrade both routers take | `test_v2_keeps_its_traffic_and_its_windows_across_the_v3_index_swap` |

## 6. Risks & the way back

- **Direction wording is inferred, not yet measured.** The aggregator names
  directions for the interface: a flow the device *sent* entered it, so `in` is
  upload and `out` is download. This follows from §1.4 and is what the router
  test must confirm — a phone that shows more up than down means it is backwards.
- **Cost is unmeasured.** One pass over `traffic_hour` for the chosen window,
  53475 rows on the larger box. The page reports the call's own timing; if it is
  slow, the window shrinks or the join moves into a materialised table.
- **Way back:** schema v3 only swaps an index. Removing the column and the block
  from the view restores stage 5 exactly.

## 7. What the router test found (2026-08-30)

`os-lens-0.4_1`, both boxes, 676 ms and 665 ms for the whole page. The join
holds: `BXY-Pixel-10` shows 1,018 MB as 38 MB up and 979 MB down, `monitoring-04`
21 up / 117 down, and the ASUS box 797 up / 84 down — a phone, a monitoring host
and a machine that serves, each shaped the way it should be. **The direction
inference in §6 is confirmed:** `in` is what the device sent.

Two defects, both of them the page asserting something untrue rather than
failing:

**"No DHCP server is running here"** on a firewall that leases every address on
its network. Lens knew dnsmasq and Kea; OPNsense ships three, and box 2 runs
isc-dhcp. Fixed and recorded as §4.27 — an absence is only as true as the list it
was asserted from. Every device on that box had lost its name for it.

**129 GB filed under "usually a gap in collection"** on the same box, where
observations had been running for 0.1 days against 1.1 days of harvested
buckets. That is not a gap, it is the past: the first harvest reaches 23 hours
back and identity begins when the collector does. Now its own class, described
as shrinking to nothing on its own (§4.28).

The accounting itself did its job: both numbers were only visible *because* the
page refuses to drop what it cannot explain.

## 8. The operator's verdict on the product (2026-08-30)

> "die Seite zeigt viele Infos, aber ist für DAUs wenig hilfreich bis wenig
> einordnenbar, wie im Vergleich z.B. UniFi mit deren Seiten und Filter"

Correct, and it moves the roadmap. Lens can now answer more than it can show —
52 rows of identical visual weight, no grouping, no filter, no search, no icons.
Recorded as BACKLOG #17 through #20 and pulled ahead of stages 8 and 9.
