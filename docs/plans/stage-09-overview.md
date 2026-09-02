# Stage 09 — The four sentences before the table (Plan)

> Systems: S6 · Depends on: stages 5, 7 and 16 · Built 2026-08-30

## 1. The problem from the user's point of view

The table answers questions you already had. It does not tell you which question
to ask. Opening Reporting: Lens should first tell you the state of the network in
a glance — how many devices are here, how much moved, who moved most, and
**whether anything showed up that was not here yesterday**.

That last one is the only line on the page with security value: a device nobody
put there is exactly what you want to be told about without having to look.

## 2. What gets built

A strip above the table, computed **from the rows below it** so the two cannot
disagree — the same reason §4.32 gave for one shared query:

- devices here now, of all Lens knows
- attributed traffic in 24 hours, and the heaviest device
- devices seen for the first time in 24 hours, named
- devices not seen for over a day

No new backend call. Every figure already exists in the device rows.

## 3. The decision (§4.34)

**"New" is withheld until Lens has watched for two days.** On a fresh install
every device is new — technically correct, practically meaningless, and worse
than absent because it looks like the real thing. The first week of "12 new
devices!" teaches the reader the number is noise, and the week it matters they
will not look. Until then the strip says how long Lens has been watching.

## 4. Test strategy

| Failure mode | Test |
|---|---|
| every device "new" on a fresh box | `testANewDeviceIsOnlyNewOnceLensHasWatchedLongerThanADay` |
| a real newcomer not named | `testOnceItHasWatchedLongEnoughANewDeviceIsNamed` |
| the strip disagreeing with the table's top row | `testTheBusiestDeviceInTheStripIsTheTopRowOfTheTable` |
| an arbitrary "busiest" on a silent network | `testAQuietNetworkHasNoBusiestDeviceRatherThanAnArbitraryOne` |
| an empty store throwing | `testAnEmptyStoreProducesASummaryAndNotAnError` |

## 5. Also in this revision

The caption under the unattributed-address list ("The heaviest twenty-five...")
was visible while the list itself was collapsed — a sentence describing a table
that was not on screen. It now hides with it.
