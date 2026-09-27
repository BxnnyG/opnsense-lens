# Stage 34 — Where a device talks (Plan)

> Systems: S2, S4, S5, S14 · BACKLOG #31 · Planned 2026-09-27 · Decision: §4.62

## 1. The problem from the user's point of view

The device page says a camera moved 1 GB, and cannot say to whom. "This camera
talks to one address on 8883 every day" is the sentence UniFi's Flows and
Zenarmor's reports are built around, and the one an admin needs when a device
behaves oddly.

## 2. What core has (verified, stable/26.7)

`FlowSourceAddrDetails` aggregates `if, direction, src_addr, dst_addr,
service_port, protocol` at **one resolution, 86400 s, kept 62 days**
(`lib/aggregates/source.py`). Like `FlowSourceAddrTotals` it writes every flow
twice, swapping the addresses for the outbound half — so on a device's segment
`src_addr` is the device, `in` is what it sent and `out` what it received.
`service_port` is `min(src_port, dst_port)`, core's own guess at the service.
Read with `get_timeseries.py --provider FlowSourceAddrDetails --resolution 86400
--key_fields if,src_addr,dst_addr,service_port,protocol,direction`.

## 3. What gets built

1. The harvest also copies each *completed* day of details, device segments
   only, **top 25 destinations per address per day** plus one summed "other"
   row — at most seven days per run, so the first backfill of 62 days spreads
   over a few runs instead of one long one.
2. Store schema 7: `destination_day`. Retention and purge include it.
3. `lens destinations --mac a,b --days N`: joined onto identity per day, the
   same rule as the hourly join — a day an address was held by two devices is
   refused, not guessed (§4.26); folded MACs (§4.61) are one device.
4. The device page gets a card: destination, service, sent and received, on how
   many days — daily, and it says so.
5. **Opt-in** (CLAUDE.md rule 9): off by default, switched on under Settings,
   where the sentence says what it keeps and for how long.

## 4. Load and disk (rule 7)

One `get_timeseries.py` call per new day — once a day after the backfill.
Storage is bounded by the top-25 cut: 25 devices × 26 rows × 2 directions ×
365 days ≈ 475 000 rows, roughly 30 MB a year at the default retention; less
with fewer devices, and the store's ceiling applies as always.

## 5. Test strategy

Parse: WAN and loopback rows dropped, the top-25 cut and the "other" row add up
to what came in. Store: a shared-address day is refused; two MACs of one folded
device sum. Settings: off by default. PHP: service names, shares, the switched-
off state. The preview seeds a camera on 8883 and a NAS backing up.
