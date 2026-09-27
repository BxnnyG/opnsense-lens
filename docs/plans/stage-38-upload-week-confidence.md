# Stage 38 — Upload on its own, last week beside this one, and how sure a type is (Plan)

> Systems: S8, S6, S1 · BACKLOG #38, #39, #41 · Planned 2026-09-27 · Decision: §4.66

## 1. The problem from the user's point of view

- A camera sending 4 GB out and a laptop pulling 4 GB of updates are the same
  verdict today, because the baseline judges the day's total. The first is the
  one worth a look (#38).
- "73 GB" means nothing without "and last week?" (#39).
- The icon says *phone* with the same confidence whether the device announced
  "iPhone" or only has an Apple MAC (#41).

## 2. What gets built

1. **Upload judged on its own.** The same three guards (§4.50) run a second time
   on what each device *sent*. A device unusual on upload is told as "sent X,
   N times its usual upload"; one unusual only in total is told as unusual with
   its uploads ordinary. Events' unusual days follow the same rule.
2. **Compared with last week.** Devices and the dashboard's traffic figure get a
   delta against the same range a week earlier (24 hours: the same day last
   week; 7 days: the week before; 30 days: the 30 before). **Withheld** when
   Lens was not collecting for the whole of that earlier range (§4.43) — no
   delta is better than one against half a week. The delta is a word and a
   number, not a colour: more traffic is not worse.
3. **How sure the type is**: *certain* (you chose it, or it is this firewall),
   *likely* (from the name the device announces), *from the maker only*, or
   *not recognised*. The name is now asked before the maker, because what a
   device calls itself is better evidence than who made its network chip.

## 3. Load (rule 7)

Upload: the daily totals already scanned carry a second sum — no new scan.
Last week: one more attribution join over the earlier range, only when it is
covered — the Devices page's cost roughly doubles for the chosen range.
Confidence: none.

## 4. Test strategy

Python: an upload-only spike is caught, a download spike is labelled as one;
the earlier range is withheld until covered. PHP: the sentences, the delta
words, and the four confidence levels.
