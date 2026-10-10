# Stage 58 — Load times, second round (Plan and record)

> Systems: S13 · After stage 54; box-2's round on 2026-10-10: the health row
> 1.68 s, Networks 1.34 s (its duty 1.16 s), the wall 1.81 s.

## 1. What the health row's own timing said

Twelve sources, 26–303 ms each, asked one after another: 1.4 s on box-2, while
the slowest alone took 0.3 s.

## 2. What is built

- `ConfigdBatch`: several configd commands opened at once through core's own
  `Backend::configdStream()` (identical on `stable/26.1` and `stable/26.7`),
  read as they arrive, each answer cleaned exactly as `configdRun()` cleans it.
- `PrefetchedBackend`: core's `Backend`, answering from a parallel prefetch; the
  code that reads each tile is unchanged — the answer is simply already there.
  Used by the health row, the System page's details and Networks.
- `interface_hour` (schema 14): every settled hour per interface and direction,
  with how much a device can be named for — filled where `device_hour` is, in
  the same pass, so Networks sums instead of joining; the unsettled tail is
  joined live. Every hour settles once more after the upgrade (240 per
  harvest). A test holds the sums equal to the live join with nothing, part
  and all settled. Forget, prune and purge include it.

## 3. Done when

The round on box-2 against the numbers above.
