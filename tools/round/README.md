# round — the router round, as one command

The checklist in [docs/ROUTER-ROUND.md](../../docs/ROUTER-ROUND.md) was a
sitting's worth of clicking. This does the part a machine can do and writes one
report per box; the human part is reading it and looking at the pictures.

```sh
tools/lens-deploy.sh                        # build and install on every box, as before
LENS_API_KEY=… LENS_API_SECRET=… \
LENS_UI_USER=… LENS_UI_PASS=… \
    tools/round/round.py --insecure         # every box in lens-deploy.sh
tools/round/round.py router-01 --no-shots   # one box, no browser
```

Output: `~/lens-round/<box>-<stamp>/report.md` and a screenshot per page —
outside the repository, so it stays clean. `--out` puts it elsewhere.

| Part | Needs | Checks |
|---|---|---|
| SSH | root key or password, as `lens-deploy.sh` | installed version against the Makefile · crontab (§4.25) · last observe and harvest, with their cost · store size · identity · today's baseline verdicts · the CPU · Unbound's `qstats totals` (the DNS view's data) · whether `ping -6 -t` is a deadline (BACKLOG #35) · the hand-run observer of August |
| API | `LENS_API_KEY`, `LENS_API_SECRET` of a user with the Lens privileges | every page's endpoint: status, JSON, time against the two-second budget · a Prometheus scrape |
| Browser | `LENS_UI_USER`, `LENS_UI_PASS`, Node with Playwright (`npm i -g playwright`) | every page at desktop and 390 px: script errors, sideways scrolling, load time, a screenshot |

Every row is **ok**, **look** or **FAIL**, with the evidence beside it; the exit
code is non-zero when anything failed. Nothing is written to the box.

`--insecure` accepts OPNsense's self-signed certificate. Secrets are read from
the environment only and never written to the report (a test says so).

## What it cannot do

Judge whether a page reads well, and see the dark theme — the box shows the
theme its user chose. Both are what the screenshots are for.
