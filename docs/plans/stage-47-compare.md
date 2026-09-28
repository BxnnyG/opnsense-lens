# Stage 47 — Two devices side by side (Plan)

> Systems: S5, S6 · Operator's request 2026-09-27 ("Gerätevergleich") ·
> Decision: §4.73

## 1. The request

Put two to four devices next to each other: how much each moved, when, and in
which direction.

## 2. What exists

`devices/history` — one device's series over a window, from the same join as
every page (§4.37); `devices/index` names every device for the palette.

## 3. What gets built

1. `devices/compare?devices=a,b|c&hours=` — up to four devices (each may be
   several folded MACs), `Compare` aligning their series on one time axis and
   saying the difference in one sentence.
2. Reporting: Lens: Compare (hidden in the menu, reached from a device's page
   and from itself): pickers, 24 h / 7 d / 30 d, one line chart with a line per
   device (one unit, one axis), legend with names, a table with total, sent,
   received, share and busiest slot.
3. Colour follows the device's slot in the URL, never its rank, from the
   validated categorical order (blue, orange, aqua, yellow); light-mode contrast
   of the last two is under 3:1, so names are always in the legend and the
   table carries every figure.

## 4. Load (rule 7)

One `lens device` per device (≤ 4) plus the device list: the device page's own
read, repeated. Measured in the preview.

## 5. Test strategy

`CompareTest`: alignment of series that start at different buckets, shares,
the sentence for two and for more, a device with no traffic.
