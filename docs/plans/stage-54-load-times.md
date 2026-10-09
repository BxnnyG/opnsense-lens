# Stage 54 — Load times, at the root (Plan and record)

> Systems: S13 · Operator 2026-10-09: "jetzt gerne die Ladezeiten" — and "du
> änderst Sachen nur da, wo es blutet, aber nicht die generelle Versorgung".

## 1. What the round measured (box-2, 2026-10-09 22:13)

| endpoint | ms | | duty on the box | s |
|---|---|---|---|---|
| dashboard/wall | 2374 | | status | 0.45 |
| devices/list 24 h | 1748 | | traffic 24 | 0.52 |
| dns/overview | 1645 | | segments 24 | 0.94 |
| dashboard/health | 1588 | | events 30 | **3.97** |
| store/status, settings/get | ~600 | | (router-01: every duty 0.13) | |

Two causes under everything, not one slow page:

1. **A Python start per read.** router-01 answers every duty in 0.13 s, the
   trivial ones too: that is the start. devices/list started Python five
   times (devices, status, traffic, baseline, internet), events four, the wall
   seven.
2. **`status` counted whole tables** — `count(*)` over 2.5 million traffic rows
   and every observation, on each call, for pages that read only its runs.

And one slow duty: **events 30**, whose daily totals summed every day not
marked done live from the first such day to now — and a day before the first
bucket is never marked done.

## 2. What is built

- `READS` in collect.py: every read-only duty in one table; the single call
  and the bundle both use it, so they cannot answer differently.
- `lens bundle`: up to twelve reads, one process, one open store, answers in
  order, a failing read null without costing the others. `LensCalls::many()`
  in PHP; the device report, Events, DNS, Privacy, Store, Settings, Metrics,
  Pause and the device pages use it — one Python start where there were up
  to five.
- `lens brief`: status without the two whole-table counts; every page that
  wants the runs reads it. The counts stay where they are shown (Store,
  Settings' purge summary, Privacy's own read, Metrics).
- `daily_totals`: only days from the first bucket on, and each gap summed
  over its own days. A test holds it equal to the live join with gaps and
  with a range reaching before the first bucket.
- events reports `took_ms` per part, so the next round says where its time
  goes instead of leaving it to a guess.

## 3. Done when

The round on box-2 shows the endpoints again, against the table above.
