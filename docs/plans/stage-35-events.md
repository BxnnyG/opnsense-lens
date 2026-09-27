# Stage 35 — What happened while I was away (Plan)

> Systems: S9, S1, S8, S16 · BACKLOG #37 · Planned 2026-09-27 · Decision: §4.63

## 1. The problem from the user's point of view

Every judgement Lens makes today is in the present tense: the one sentence says
what is unusual *today*, the dashboard shows the *last* outage, Services: Lens
counts address overlaps without saying when. Someone back from a weekend has no
page that answers "what happened". UniFi's alarm list, Firewalla's feed and
Zenarmor's event reports all open with that list.

And the NAS backs up every night: once a verdict is known to be noise, the
operator needs to say so once and not read it every morning.

## 2. What core and Lens already have

Nothing new is collected. Five events are derivable from the store:

| Event | From | Grain |
|---|---|---|
| a new device | `device.first_seen`, with §4.34's two-day guard | 5 min |
| a phone rotating its address | a folded row (§4.61) whose newest MAC appeared later than the row | 5 min |
| an unusual day | `baseline.assess` re-run for each past day on the history before it | day |
| the internet unreachable | `uptime.assess` over probe rounds (§4.57) | 5 min |
| a gateway down or degraded | runs of `gateway_sample.status` other than `none`, at least three samples | 5 min |
| an address held by two devices at once | the overlap query `identity_health` counts (§4.36), with its time | 5 min |

## 3. What gets built

1. `lens events --days N` (N ≤ 30): the raw events, per kind, in the store's own
   terms. The unusual days reuse `baseline.assess` with the day under test as
   "today" and the same window the live verdict uses.
2. `Events` (PHP, pure): one chronological list over the folded device rows —
   each event a sentence, a status word, a time, and the page that proves it.
   Rotation is told apart from a new device here, where the fold lives.
3. `Reporting: Lens: Events`: range 24 h / 7 d / 30 d, kind chips, a day
   heading per day, muted devices folded away with a count and a toggle.
4. **Mute per device** (schema 8: `device_label.muted`): from an event or from
   the device page. A muted device's events fold away on Events, and it is never
   the subject of the one sentence. Its figures and verdicts stay where they are
   — muting hides news, not facts. Muting a folded phone mutes every MAC of it.

## 4. Load (rule 7)

One configd call more per Events page load, on demand only. The heaviest part is
the daily totals behind the unusual days: `N + baseline_days + 1` days of the
attribution join, i.e. at most 52 days at the defaults — the same join the
Devices page runs for its 30-day range. The collector gains nothing.

## 5. Test strategy

Python: each kind from a hand-built store — the two-day guard, the three-sample
floor for a gateway, a past unusual day judged on the history before it, an
overlap with its time. PHP: rotation versus new, sentences, the muted fold,
sorting. Store: the migration keeps labels, and a mute survives a rename.
Preview: the seeded outage, the NAS's night, the rotating Pixel.
