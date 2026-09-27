# Stage 33 — One phone, not four; and the menu that stays open (Plan)

> Systems: S1, S6 · Depends on: stages 5, 6, 26 · Planned 2026-09-27 · Decision: §4.61

## 1. The problem from the user's point of view

> "dann habe ich handy mit random mac teilweise 3 4 mal drinne, maybe könnte man
> das verbessern im sinne von hostname und timestamp" — and: "wenn man von all
> devices auf ein device klickt, dann verschwindet links die nav".

A phone that rotates its private MAC becomes a new row each time: four rows,
three of them "not seen for 3 days", the history split four ways, and on the day
it rotates it is also counted as a *new device* — a false alarm in the one line
people read. BACKLOG #3 predicted this failure; §4.36 measures it; nothing acted
on it. Separately, `/ui/lens/device` has no menu entry, so core selects nothing
and the sidebar collapses.

## 2. What gets built

1. `IdentityFold` (PHP, pure): randomised MACs that announce the **same
   hostname**, share a **segment**, and **never held an address at the same
   time** are one device. Generic names (`iPhone`, `android`, `*`, …) never fold.
2. DeviceReport folds them into one row: the newest MAC leads, traffic and
   addresses are summed, the name the operator gave any of them wins, and the
   row says why ("one phone, 3 private addresses since 12 Sep").
3. The device page, its chart, heatmap, history and drill-down cover every MAC
   of the row (`lens device --mac a,b,c`).
4. Settings: "treat a phone's rotating addresses as one device", on by default.
5. Menu: a hidden child under Devices for `/ui/lens/device*`, as core does for
   `system_authservers.php*`.

## 3. Decisions

- **Display, not data.** The store keeps one row per MAC; the fold is derived
  every time and switched off by one setting. Nothing is rewritten that could
  not be un-written.
- **Never overlapping is the evidence.** Two phones of the same model share a
  hostname; if they were ever home at the same time they are two chains, not
  one. If they never were, Lens cannot tell them apart — that is stated.
- An hour in which one address passed from the old MAC to the new one stays
  refused by the attribution join (§4.26); folding does not widen it.

## 4. Test strategy

Recorded shape: three randomised MACs, one hostname, consecutive windows → one
row, summed traffic, earliest first-seen (so not "new"). Two overlapping → two
rows. Generic hostname → no fold. Different operator names → no fold. Different
segments → no fold. Setting off → no fold. Store queries over a MAC list sum to
the per-MAC totals. The preview seeds a rotating phone so the page is looked at.
