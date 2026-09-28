# Router round for `os-lens-0.27_1` (2026-09-27)

> Why this exists: nineteen stages, stages 30 and 31 included, have been built
> since the last click-test on a box (`0.5_2`, 2026-08-31). PROCESS calls each of them
> "plausible" until a human has clicked it on a router and the load has been
> measured. This is that, in one sitting, in the order that finds the expensive
> failures first.
>
> Tick a box when it holds. When one does not, write what the box said
> underneath it — the exact output, not a paraphrase — and stop there: that line
> becomes the fixture for the fix (CLAUDE.md rule 5).
>
> The root shell is **csh**: no `$(...)`, no `2>&1`. Every command below is
> written for it.
>
> **Most of this list is now one command** (stage 32, §4.60):
> `tools/round/round.py --insecure` with an API key and a GUI login in the
> environment — see [tools/round/README.md](../tools/round/README.md). It checks
> sections 0, 1, 3 and the API and page load of 2, screenshots every page at
> desktop and 390 px, and writes one report per box. What is left for a person
> is reading that report, looking at the pictures, and the few items that change
> something on purpose: the Settings round trip, the fix button, purge.

## 0. Install

    tools/lens-deploy.sh            # both boxes, from a checkout of this branch

- [ ] router-01: `pkg info -x os-lens` says `0.27_1` — named `os-lens-devel`
      when built on the box, because `Mk/devel.mk` marks every build from this
      repository as a development build (stage 40)
- [ ] second box: the same
- [ ] `grep -n lens /var/cron/tabs/root` prints two lines, `*/5` and `*/30` (§4.25)
- [ ] `configctl lens status` answers JSON, `schema_version` is `6`
- [ ] the hand-run observation loop from 2026-08-29 is gone:
      `ps ax | grep lens-observe` shows only the grep. If it runs:
      `pkill -f lens-observe` (tools/README.md). The collector replaced it at stage 4.

## 1. The box is still a firewall (PROCESS §4, rule 7)

Do this before any page. A reporting plugin that slows the router has negative
value, and stage 29 made the observe duty wait on the network for the first time.

- [ ] **observe cost:** `configctl lens status`, read `runs.observe.took_ms` three
      times across fifteen minutes. Measured at 7 ms on 2026-08-30; with three
      pings of three echoes each it should now be about 2–4 s, almost all of it
      waiting. Write the three numbers here:
      `router-01: ____ / ____ / ____ ms   second box: ____ / ____ / ____ ms`
- [ ] **CPU while it waits:** during a run, `top -b -o cpu | head -15`. Nothing
      from Lens above a few percent — waiting on ping is not work.
- [ ] **harvest cost:** `runs.harvest.took_ms`. Was 459–653 ms on router-01.
      `____ ms`
- [ ] **page loads:** open each Reporting: Lens page once. None takes longer than
      about two seconds. The Devices page prints its own timing at the bottom.
- [ ] **the web interface stays responsive** while the dashboard is open and
      refreshing.
- [ ] **store size:** `ls -lh /var/db/lens/lens.sqlite` → `____ MB` after ~28 days.
      Projection on 2026-08-30 was ~130 MB a year on router-01.

## 2. Page by page

### Services: Lens

- [ ] **Data Sources** loads, three verdicts, no amber that you cannot act on
- [ ] **the fix button** (§4.35, built 2026-09-02, never clicked): if the page
      offers "capture …", the dialogue names the setting and shows before/after.
      Press it only on the box where the interface really should be captured.
- [ ] **Settings** (stage 30) — new in this round:
  - [ ] the page loads, four blocks, every field shows its default
  - [ ] set *gone after* to 5 → save refuses with "The smallest this can be is
        10." and nothing else changed
  - [ ] set retention to 364 → saved → `configctl lens status` shows
        `retention_days: 364` → set it back to 365
  - [ ] switch pinging off → save → within five minutes
        `configctl lens status` shows `runs.observe.detail` ending in
        `probes switched off`, and `runs.observe.took_ms` drops back to tens of
        ms. **This is the measurement of what the probes cost** — write it:
        `with ____ ms, without ____ ms`
  - [ ] the dashboard's internet panel then says pinging is switched off and
        still shows a state (from the gateway), not "Offline"
  - [ ] switch pinging back on
  - [ ] a target `192.168.1.1` is refused with "not on the internet"
  - [ ] log in as a user who holds **only** the Reporting: Lens privilege:
        `/ui/lens/settings` is refused, and every Reporting page still works
  - [ ] **purge** — only on the box whose history you are willing to lose, or
        not at all this round: the dialogue lists what goes and what stays; after
        it, Devices is empty and fills again within five minutes; Settings still
        shows your values

### How it looks (stage 31)

The preview (`tools/preview`) already showed every page at desktop, dark and
390 px. What it cannot show is a real phone on the real box:

- [ ] open the dashboard and Devices **on your phone**: nothing scrolls sideways,
      the device list is one card per device, the heatmap scrolls inside its card
- [ ] switch to the dark theme (System: Settings: General, theme `opnsense-dark`,
      or `opnsense-auto` with a dark phone): no card has a bright frame, every
      chart and status word is readable
- [ ] hover the network chart: one readout with both directions and the time
- [ ] Devices, a device page and the dashboard all say HOME / IOT / … where
      Networks does

- [ ] **Privacy** (stage 46): the counts match Services: Lens's store figures;
      a user with only the Reporting: Lens privilege does not see the page, and
      the device page's "Forget..." leads to it. Forget one throw-away device
      (a guest phone): the preview's counts, then the same counts deleted, and
      the device is back after the next observation if it is still there

### Reporting: Lens

- [ ] **Dashboard** (stage 23): five tiles, network over time, top devices,
      networks donut, system card. Compare memory %, disk % and uptime with
      core's own system widget — they must agree exactly (§4.51)
- [ ] **the one sentence** (stage 25): reads calm when all is well; pull the WAN
      cable for a minute on a test box, or wait for an outage — does it say the
      internet is unreachable within five minutes?
- [ ] **internet panel** (stage 29): WAN IPv4 and IPv6 are the ones core's
      interface overview shows; the three round trips look plausible (Quad9 and
      Cloudflare usually single-digit to ~20 ms from a German PPPoE line)
- [ ] **the line** (stage 28): each monitored gateway, state matches
      System: Gateways exactly
- [ ] **Devices** (stages 16–22): range picker 24 h / 7 d / 30 d, a network chip,
      a tag chip, search, CSV export opens in a spreadsheet with umlauts intact
- [ ] **drill-down** (stage 19): click a bar on a device chart — the parts add
      up to the bar
- [ ] **device page** (stage 26): open three devices, one with a randomised MAC.
      Heatmap in local time, address history, presence
- [ ] **Who's home** (stage 24): the herd folded away, the phones on top
- [ ] **one phone, not four** (stage 33): a phone that showed up three or four
      times is one row with "3 addresses"; its device page covers all of them;
      Settings → *One phone, not one row per private address* off brings the rows back.
      Open a device from Devices — the menu on the left stays open
- [ ] **Events** (stage 35): 7 days lists what you remember happening — the
      last outage, a new device, the NAS's night. Mute one device from an event:
      it folds away behind "1 from devices you muted", its page says *muted*,
      and the one sentence on the dashboard no longer leads with it. Unmute from
      its page. Note the page's load time from the round report
- [ ] **people and the week** (stage 39): give two phones and a laptop an
      owner (Belongs to). Who's home shows one strip per person, from the phone,
      and says so. Weekly report: print it to PDF from the browser — one clean
      column, no OPNsense menu on the paper
- [ ] **upload, last week, type** (stage 38): Devices at 24 hours shows a
      grey +/−% beside each traffic figure once Lens has watched eight days;
      `configctl lens baseline` — does any verdict now read *sent*? Is it the
      camera or the NAS you would expect? The device page says how sure its
      type is
- [ ] **palette and filter** (stage 37): Ctrl-K on any Lens page, type part
      of an address — the device that holds it comes first; Enter opens it.
      Pick IOT on Devices, then open Who's home and Events from the menu: both
      say "Filtered to Network: IOT", and *clear* clears it everywhere
- [ ] **where it talks** (stage 34): the card on a device page says it is off
      and links to Settings. Switch *Keep who each device talks to* on, then
      `time configctl lens harvest` — note the seconds (the first run fetches up
      to seven days). Next day: a camera or TV lists a handful of destinations
      with plausible services; the store size on Services: Lens grows by
      megabytes, not hundreds
- [ ] **Networks** (stage 17): cards, rings, sparklines; a card opens its devices
- [ ] **Wallboard** (stages 14, 45): on the real screen it will hang on. Fill
      the screen: clock, figures, the live panel and *Just now* all fit without
      scrolling, and are readable from across the room. The round's timing for
      `dashboard/wall` stays under a second — it is asked every minute
- [ ] **dashboard widget** (stage 10): add it to core's dashboard, top five shown

### `/metrics` (stage 27)

From a machine with an API key of a user holding Reporting: Lens:

    curl -sk -u KEY:SECRET https://router-01/api/lens/metrics/prometheus | head -40

- [ ] text format, `lens_collector_last_run_seconds` present and small
- [ ] one real Prometheus scrape succeeds (the second box already runs Telegraf)

## 3. Questions only the box can answer

These have been open since the dates beside them. Each is one command.

- [ ] **The first baseline verdicts** (§4.50, day 21 passed ~2026-09-20).
      `configctl lens baseline` → how many `unusual`, and are they real?
      For each: would you have wanted to be told? If most are noise, the
      numbers to change are now on Settings — and write down which ones.
- [ ] **The DNS view's missing output** (S12, open since 2026-08-30; built
      from core's source as stage 36, §4.64).
      `configctl unbound qstats totals 10` on the second box (Unbound records
      there). Paste the output into `tests/fixtures/` — it replaces what the
      tests assume. Then Reporting: Lens: DNS on that box: do the devices
      asking most have names, and does a user without *Reporting: Lens: DNS*
      get no DNS at all? On router-01 the page must say dnsmasq keeps nothing
- [ ] **The 95 GB on the second box** (open since 2026-08-30). Networks page,
      24 h: which segment carries the traffic that has no device? One VLAN at a
      low ring → routed networks become their own class (§4.31). Something else
      → the guess was wrong, write down what it is.
- [ ] **IPv6 probe targets** (BACKLOG #35). On the box:
      `ping -6 -c 3 -t 4 -q 2620:fe::fe` then `echo $status`.
      If it runs and stops after at most four seconds, `-t` is a deadline for
      IPv6 too and IPv6 targets can be allowed.

## 4. After the round

- ROADMAP: every ticked stage becomes ✅ with the date and `0.27_1`; the
  measured numbers go into the operations notes (observe with/without probes,
  harvest, store size).
- DESIGN §1b: the same.
- Anything that failed: one issue per failure, the output above as its fixture.
