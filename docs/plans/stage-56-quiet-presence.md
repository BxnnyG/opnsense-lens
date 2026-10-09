# Stage 56 — Presence of quiet devices (Plan)

> Systems: S1, S5 · Seen on box-2's Who's home, 2026-10-09: servers that never
> go away (sso, forum, the Proxmox guests) at 18–20 of 24 hours, in a regular
> comb of gaps · Operator: "gerne"

## 1. Why the gaps are there

Presence is read from the ARP and NDP tables every five minutes, and an
address window stays open while a device is seen again within the
observation gap (900 s). A server that sends nothing for a while — a quiet VM,
an idle service — drops out of the ARP table when its entry expires, and its
window closes although it never left. The strip of stage 55 drew those holes;
the solid bar before it hid them.

## 2. The rule

A gap between two windows of **the same device, on the same address and
interface**, is bridged **hour by hour where that address moved traffic** —
NetFlow saw packets to or from it, so something answered there. Only:

- when no other device held that address in the gap (an address two devices
  held stays with nobody, as everywhere in Lens, §4.36);
- for the hours with traffic, clipped to the gap — never the whole gap on
  the strength of one hour;
- gaps up to 24 hours; a longer silence is a device that went away.

A lease is still not presence (§4.77): it says an address was handed out, not
that anyone answered.

## 3. What gets built

- `presence.bridges(windows, traffic)` (pure): the intervals the rule adds,
  per device.
- The `presence` duty reads windows with their address and interface, the
  hours with traffic for those addresses, merges both; it reports per device
  how many seconds came from traffic, so the page can say it.
- The duty no longer asks the full `status()` (two whole-table counts) for
  the first observation.

## 4. Load (§4.8)

One indexed query for the hours with traffic over the range
(`traffic_hour_by_address`), grouped; the bridging is in memory.

## 5. Done when

Tests for every clause above; Who's home on box-2 shows the servers at their
real hours; the round times the presence duty no slower than 0.43 s.
