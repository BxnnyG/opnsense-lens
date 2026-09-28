# Stage 44 — Who asked what, and how often (Plan)

> Systems: S12, S5 · Operator's request 2026-09-27 · Decision: §4.70

## 1. The request

"What did who call up, how much, what was blocked most, what was called most"
— and a DNS heatmap per device.

## 2. What core has (verified, stable/26.7)

`site-python/duckdb_helper.DbConnection`: core's own way to read the Unbound
store beside the logger that writes it — one short read-only connection, a
flock handshake with the writer. `stats.py` uses exactly that. The table
`query` holds every question for seven days (§4.64). What `stats.py` does not
offer is a per-client aggregate: its `details` call returns the newest 500.

## 3. What gets built

1. The collector reads the store through core's helper: every question of the
   range, summed per client, name and hour in DuckDB, then each hour put on the
   device that alone held the address — the traffic join's rule.
2. DNS page, 24 hours or 7 days: *Who asked what* (each device, its questions,
   blocked share, and its names on demand) and *What was asked, and by whom*.
3. The device card counts every question, not a sample, and draws the device's
   week of questions as a 7 × 24 heatmap in local time.
4. Where the store cannot be read — no DuckDB module, no file — stage 36's
   `stats.py` path answers as before.

## 4. Load (rule 7)

One aggregate query per page open, on the store core's own page reads; the
logger waits for it, as it waits for core's page. Measured on the preview's
week (155 000 questions): 0.3 s for 7 days.

## 5. Test strategy

A DuckDB file in core's schema, read through core's actual helper from the
checkout: every question on the holder of the hour, the figures and names from
the same rows, the card's hours and heatmap, and the fall-back. Skipped where
the module is missing.
