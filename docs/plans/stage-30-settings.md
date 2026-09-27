# Stage 30 — Settings (Plan)

> Systems: S14, S16, S8, S2 · Depends on: stages 4, 12, 28, 29 · Planned 2026-09-27
>
> Decision: §4.58.

## 1. The problem from the user's point of view

> "einstellbarkeit ist auch geil also doch alles einstellbar machen was geht"
> — the operator, 2026-09-27, after §4.57 was pointed out as the one thing Lens
> does to the network that nobody can switch off.

Four numbers that decide what Lens keeps and when it speaks have been fixed in
code or in a table no page can reach: how long history is kept, how large the
store may grow, how long a device may be silent before it counts as gone, and
the three guards on "unusual". The probes to public resolvers have no switch at
all. And S14's promise — "a purge action exists and works" — is true only from
a root shell: the ROADMAP says "no button until there is a settings page to
confirm on".

Person 1 (the admin) has this problem. Nobody else opens Services.

## 2. What gets built

A page, **Services: Lens: Settings**, in four blocks:

1. **Keeping data** — retention (days), disk ceiling (MB), and *gone after*
   (minutes without being seen before a device counts as away — the store's
   `observation_gap`, which is what decides "who's home").
2. **The internet panel** — probes on or off, and up to three targets, each a
   name and an IPv4 address. Default: on, Quad9, Cloudflare, Google (§4.57).
3. **The line** — whether gateway samples are stored for the history chart.
   The live reading comes from core either way.
4. **Judging unusual** — learning days, the multiple, and the floor (§4.50).
5. **Delete everything** — purge, behind a dialogue that states what goes and
   what stays (the settings themselves stay; collection resumes at the next
   observation).

Every field shows its default and says what changing it does, in the words of
the thing it changes. Shortening retention says when the deletion happens (the
nightly prune at 04:17), not just that it will.

## 3. The decisions, not the drawing

- **Where settings live: the store's `setting` table, not `config.xml`.** The
  table exists since stage 4 and the collector already reads it; putting them in
  a model would mean a template, a reload, and a second place the collector has
  to parse. The cost is stated in §4.58: they are not in a configuration backup.
- **Validation lives in Python, once.** `lenslib/settings.py` owns the keys,
  the defaults and the bounds; the collector refuses anything outside them and
  says which field and why, as a code. `Settings.php` turns codes into
  sentences and the spec into a form. The browser enforces nothing it could be
  talked out of.
- **The write path is the label path** (§4.30): fields travel base64url through
  configd's parameter list, so a target name with a space never has to survive
  as punctuation.
- **Settings sit behind Services: Lens only.** `api/lens/settings/*` is not in
  the Reporting privilege — a user who may read the reports may not shorten
  their retention. The reports learn that probes are off from `lens internet`,
  not from the settings endpoint.
- **IPv4 targets only.** FreeBSD's `ping -t` is the deadline §4.57 relies on;
  whether the same flag means the same thing for an IPv6 target has not been
  seen on the box (CLAUDE.md rule 2), so it is not offered.
- **Not in this stage: switching off observation or the harvest.** S14 asks for
  every source to be switchable, and these two are sources. But every page reads
  a stopped observe duty as "the collector has stopped" — the loudest thing the
  one sentence can say — and teaching each surface the difference between
  "stopped" and "paused on purpose" is its own stage. BACKLOG #34.

## 4. Edge cases walked

0. *Would this warning ever go green?* Probes off is not a warning: the panel
   says "switched off under Services: Lens: Settings", grey. The state falls
   back to the gateway, as it already does when no probe has run.
1. *No data / source off:* a fresh store has no `setting` rows beyond the
   stage-4 three; every new key falls back to its default on read.
2. *Identity:* *gone after* changes only windows opened from the next
   observation. Closed windows are not rewritten — history keeps the gap it was
   recorded under, and the page says so.
3. *Load:* one extra read per observation (the settings, one table of eight
   rows). Probes off saves the four-second deadline per observation.
4. *Disk:* nothing new is stored except settings rows.
5. *Privacy:* retention and purge become reachable without a shell — the point.
6. *Untrusted strings:* target names are operator input, not LAN input, but are
   still restricted to `[A-Za-z0-9 ._-]{1,24}` and escaped on output.
7. *IPv6:* see the decision above; stated on the page.
8. *Packaging:* no schema change — `setting` exists. One new page, one new
   controller pair, two new configd actions, a menu entry, ACL patterns.

## 5. Test strategy

- `test_settings.py`: every bound, both sides; a non-number; targets — none,
  four, a hostname, an IPv6 literal, a name with a semicolon; defaults on an
  empty table; a stored garbage value falls back rather than crashing observe.
- The join (PROCESS): the collector's `configure` duty writes, and `baseline`
  and `internet` read what it wrote — driven through `collect.py` against a
  temporary store, as `test_harvest.py` already does.
- `SettingsTest.php`: the form carries value, default and bounds from the
  collector's answer; every error code has a sentence; an unknown code does not
  vanish.
- `InternetTest.php`: probes off is its own state, and does not read as offline.
- `StoreReportTest.php`: the learning period follows the setting.

## 6. Risks & the way back

- **A setting is a way to be wrong on purpose.** A floor of 1 MB makes the
  unusual column noise. Bounds stop the absurd; the page states the default
  beside every value so the way back is one number.
- **Not in a backup.** Stated on the page. If that turns out to matter, the
  table moves to a model; the keys and bounds stay where they are.
- **Built without a router.** Like the seventeen stages before it, this is
  "plausible" until clicked on the box.
