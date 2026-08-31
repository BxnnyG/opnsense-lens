# Stage 08 — The device, hour by hour (Plan)

> Systems: S5 · Depends on: stages 5, 6, 7 and 16 · Built 2026-08-30

## 1. The problem from the user's point of view

The list says `bxy-cachyos-x8664` moved 9.1 GB yesterday. That is already more
than OPNsense can tell you. It is also the beginning of the question, not the
end: 9.1 GB *when*? All night, or in twenty minutes at four in the afternoon?
On which segment?

Clicking the name now answers that: one bar per hour, sent below and received
above, the busiest hour named, and the segments it was seen on.

## 2. What gets built

1. `Store.device_traffic(mac, since)` — one device's hourly totals by direction.
2. **`ATTRIBUTION_SQL`, written once and used by both readers.** The list and
   the detail must agree; two copies of a join drift the first time one is
   corrected, and a detail total that contradicts the row it was opened from
   makes both numbers unusable with no way to tell which lied.
3. `collect.py device --mac --hours`, and the configd action.
4. `DeviceDetail` — pure: the series, the peak, the busiest hour, and a note
   when the history is shorter than the window asked for.
5. A modal with a hand-drawn SVG chart. No charting library: it is twenty-four
   stacked bars, the page ships no dependencies today, and a library would
   decide the axis and the rounding for us.

## 3. The two things that are decisions, not drawing

- **An empty hour is drawn.** A chart over only the busy hours makes a device
  that was quiet all night look like one that was busy all night, and a gap
  where a zero belongs says "no data" when the truth is "nothing happened".
  Quiet hours keep a thin line so quiet cannot be read as missing — the same
  distinction as §4.22 and §4.28.
- **The detail refuses exactly what the list refuses.** An hour a device shared
  an address with another is in neither, and the page says where it went
  instead (§4.26).

## 4. Test strategy

| Failure mode | Test |
|---|---|
| detail total disagreeing with the list | `test_the_detail_total_equals_what_the_list_shows_for_that_device` |
| a shared hour credited here but refused there | `test_the_shared_hour_is_absent_from_the_detail_exactly_as_from_the_list` |
| the far end reaching a device's own chart | `test_the_far_end_never_reaches_a_devices_detail` |
| a quiet hour drawn as a gap | `testAQuietHourIsAZeroAndNotAMissingBar` |
| a short history read as a quiet day | `testAShortHistorySaysWhyRatherThanLookingLikeAQuietDay` |
| a device that moved nothing given a fake peak | `testADeviceThatMovedNothingHasNoBusiestHourRatherThanAFakeOne` |

## 5. Risks & the way back

- **One query per opened device.** Bounded by the store, measured by the page,
  and only run on a click. If it is slow on the box with 122913 buckets, the
  window shrinks before anything is cached.
- **Way back:** additive. Removing the click handler leaves stage 16 intact.
