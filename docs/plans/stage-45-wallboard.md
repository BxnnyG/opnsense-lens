# Stage 45 — A wall worth looking at (Plan)

> Systems: S4, S10 · Operator's request 2026-09-27 (with a sketch, "could, not
> 1:1") · Decision: §4.71

## 1. The request

"Make the wallboard better, more information", and a ticker of the latest
events. The sketch: clock and date large at the top left, the key figures large
at the top right, a large live panel, a "just now" list on the right that fades
out at the bottom.

## 2. What exists

`/api/lens/devices/list` (figures, sentence, groups), `/api/lens/events/list`
(§4.63), the internet state (`Internet`), the network's 24 hours (`timeline`),
people from owners (§4.67). The board today calls only the first.

## 3. What gets built

1. `/api/lens/dashboard/wall` — one call composing all of it with the same
   models, so the board cannot disagree with the pages (§4.37). The folding of
   herds moves from the board's JavaScript into `Wall` (rule 3).
2. Layout: clock and date; devices here, moved in 24 h with the change against
   the day before, new, events today. Left: internet state and round trips, the
   network's 24 h (received/sent, legend), the heaviest devices. Right: *Just
   now* — the latest events, newest first, fading out; below it who is home.
3. Not built: a world map. Lens has no location of any address (no GeoIP,
   #12); a map would be invented.

## 4. Load (rule 7)

One request a minute instead of one: it adds `lens events 1` and
`lens timeline 24` to what the list already runs, and reuses its devices,
status and internet reads. `lens presence` is not needed — who is home now is
the device rows' `here`. Measured in the preview on the synthetic 1.2 M row
store before shipping.

## 5. Test strategy

`WallTest`: folding, events without muted ones, people here first, the counts
of today, missing parts leave their slot empty rather than failing the board.
Screenshots light, dark, 1920×1080 and phone.
