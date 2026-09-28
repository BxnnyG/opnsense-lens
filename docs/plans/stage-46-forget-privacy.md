# Stage 46 — Forget one device, and say what is kept (Plan)

> Systems: S14, S2 · Operator's request 2026-09-27 ("Alles zu diesem Gerät
> löschen", "Datenschutz-Seite") · Decision: §4.72

## 1. The request

Delete everything Lens holds about one device, and one page that says what
Lens keeps about the people on the network, for how long, and what it reads
without keeping.

## 2. What exists

Purge (everything) and prune (by age) since stage 30 (§4.58). The store keys
traffic and destinations by address, not by MAC: a device's traffic is the
hours its address windows cover (§4.26). Core keeps its own copies — Unbound's
questions, flowd's aggregates, leases — which Lens reads and cannot delete
(rule 6).

## 3. What gets built

1. `Store.forget(macs, dry)`: the device rows, its label, its summed days, its
   address windows, and every traffic hour and destination day those windows
   cover — also hours another device shared (they were attributed to nobody,
   and they describe this device too). `secure_delete` for the statement, so
   the rows do not linger in free pages. `dry` counts without deleting.
2. `collect.py forget --mac a,b [--dry]`, configd `forget` and `forget.preview`.
3. Services: Lens: Privacy (`ui/lens/privacy`, Services privilege — deleting
   is not reading, as with purge): what Lens keeps, per kind, with count,
   oldest record and retention; what it reads and does not keep, with where
   core's copy lives; forget one device (pick, preview counts, confirm).
4. The device page links to it ("Forget this device…") with the MACs filled in.

## 4. Load (rule 7)

A forget is a one-off write on the seek index (address, bucket); measured on
the synthetic store. The page's counts are one `count(*)` per table.

## 5. Test strategy

Python: forget removes exactly the device's rows and the traffic its windows
cover, leaves a neighbour's hours, counts equal in dry and wet, a folded device
forgets all its MACs. PHP: the page's model (kinds, oldest, what core keeps).
