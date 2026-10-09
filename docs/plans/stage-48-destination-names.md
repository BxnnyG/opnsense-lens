# Stage 48 — Names for destination addresses (Plan, not started)

> Systems: S5, S12, S14 · Operator's request 2026-09-27 ("Namen für Ziel-IPs,
> wenn es geht mit 100 % genau") · Decision: §4.89 (2026-10-09)

## 1. The request

"Where it talks" lists outside addresses. The operator wants names for them,
exact if at all possible, other lookups only as a fallback.

## 2. What core has (verified, stable/26.7)

- Unbound's query log (`logger.py`, table `query`): `time, client, family,
  type, domain, action, source, blocklist, rcode, resolve_time_ms,
  dnssec_status, ttl`. **No answer addresses.** Which address a name resolved
  to is not recorded anywhere core keeps.
- `configctl unbound dumpcache` (`wrapper.py -c`, `unbound-control
  dump_cache`): every RRset in the cache now — `host, ttl, rrtype, value` —
  A, AAAA and CNAME included. Exact for what it holds, and only while it holds
  it: a CDN answer with a 60 s TTL is gone long before the next harvest.
- flowd: addresses and ports; no TLS SNI, no HTTP host.

## 3. What "100 % exact" can and cannot mean

Not possible in general: one CDN address serves thousands of names, and a
device using DNS over HTTPS never asks Unbound. What is exact:

1. **Resolved here** — Unbound's cache said, at a moment Lens saw, that name N
   (following CNAMEs back to the name asked) had address A.
2. **Asked by this device** — the same device asked Unbound for N on that day
   (query log, by client, the §4.70 attribution).

Both together is the strongest statement available: "this device asked for N,
and N was A". Only (1): "A was N for someone on this network". Neither: no
name — never a PTR guessed into a name; a PTR may be shown as what it is,
labelled "reverse DNS says", opt-in, because it is a lookup to the outside.

## 4. What gets built (if the operator agrees)

- A `names` duty on observe (every 5 min): dump the cache, keep A/AAAA with
  their CNAME chain, store `(address, name, first_seen, last_seen)` for the
  addresses that appear in `destination_day` only — not the whole cache.
- "Where it talks" shows the name with its strength (asked by this device /
  resolved on this network), the other names for the same address on demand.
- Opt-in on Services: Lens: Settings next to destinations, inside retention,
  purge and forget (S14).

## 5. Load (rule 7) — to be measured before building

`dump_cache` on a busy resolver can be tens of thousands of lines every five
minutes. Measure on the second box: size, time, and Unbound's own latency
while it dumps.

## 6. Decided by the operator (2026-10-09, §4.89)

- Store names: **yes**, opt-in, inside retention, purge and forget.
- PTR lookups: **yes**, opt-in, labelled "reverse DNS says".
