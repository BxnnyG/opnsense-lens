# Stage 41 — Pages that answer on a busy box (Plan)

> Systems: S2, S4, S13 · Reported 2026-09-27 by the operator · Decision: §4.69

## 1. The report

Events, Devices and Who's home sat on "Reading..." on router-01; the weekly
report took over seven seconds. The router round of the same hour showed the
package not installed and no collector run, so the store on the box is what an
earlier install left.

## 2. Reproduced (rule 5)

A synthetic store the size of the second box — 220 devices, 44 000 address
windows, 1.24 million traffic rows over 60 days — timed per duty on a fast
container CPU (a firewall's is several times slower):

| duty | before |
|---|---|
| `events 7` | 8.3 s |
| `baseline` | 5.0 s |
| `traffic 168` | 4.1 s |
| `/api/lens/devices/list` | 5.0 s |

Every one of them is the attribution join. For each traffic row it scanned
every earlier window of that address (`first_seen < bucket + 1 h` on an index
that cannot bound the other side), so its cost grew with windows × rows. And
stage 38's comparison wrapped the join instead of bounding it, scanning from
the earlier range to now.

## 3. What gets built

1. **Windows are at most a day long.** Observe starts a new piece of a window
   that has run a day; migration 10 cuts existing long windows the same way.
   With a span index `(address, interface, first_seen, last_seen)` the join
   seeks `first_seen >= bucket − 1 day` — a handful of windows, not hundreds.
   Pieces are storage only: every reader gets them joined back into stays, so
   visits, address history and presence read exactly as before.
2. **The join takes an upper bound**, so a week-old range scans that week.
3. **Complete days are summed once** (`device_day`), by the harvest, for the
   baseline and Events; only today is computed live. A late bucket for a day
   drops that day's sums, and the next harvest redoes them. Prune and purge
   take the table.
4. The router round **times each duty on the box** and flags anything over two
   seconds, so the next report says which one is slow instead of "Reading...".

## 4. Load (rule 7)

The harvest does more (one day's join per missing day, at most 31 per run),
once. Every page read does much less.

## 5. Test strategy

Store: the split migration keeps every second of every window and adds none;
observe cuts at a day; readers see stays; the bounded join gives the same
attribution as the old one on the same store; cached days equal live days;
a late bucket drops its day. Timings recorded before and after.
