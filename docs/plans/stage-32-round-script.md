# Stage 32 — The router round, as a script (Plan)

> Systems: S15 · Tools only, nothing ships · Planned and built 2026-09-27

## 1. The problem from the user's point of view

> "kein bock auf router round, maybe kann man das skripten mit auto bildgen —
> aber es soll trotzdem clean bleiben das repo" — the operator, 2026-09-27.

Nineteen stages wait on a checklist of forty items. Most of them are a command
and a comparison; a machine can run them and say what it found.

## 2. What gets built

`tools/round/round.py`: per box from `lens-deploy.sh`, SSH checks, API checks and
screenshots through `tools/preview/shoot.js` (which learns to log in), each with a
verdict and its evidence, in `~/lens-round/<box>-<stamp>/report.md`.

## 3. Decisions

- Output outside the repository by default; secrets from the environment only.
- The box list is read from `lens-deploy.sh`, not copied.
- The remote part is POSIX `sh -c`, because root's shell on the box is csh.
- Read-only: nothing on the box changes. Measuring observe with probes off
  would mean switching them off; the report shows the cost it has instead.

## 4. Test strategy

`tests/python/test_round.py`: the verdicts over recorded shapes — a healthy box,
an old package, a missing crontab, a stopped collector, an unusual day, Unbound
recording nothing, a hanging IPv6 ping, and that no secret reaches the report.
Run against the preview for the API and browser halves.
