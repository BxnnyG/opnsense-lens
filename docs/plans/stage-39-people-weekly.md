# Stage 39 — Who's home by person, and the week on one printable page (Plan)

> Systems: S1, S5, S6 · BACKLOG #40, idea store "weekly report" (DESIGN §2b) · Planned 2026-09-27 · Decision: §4.67

## 1. The problem from the user's point of view

Who's home is one strip per *device*. The question people ask is "is Anna
home", and Anna is a phone, a laptop and a watch. Zenarmor groups by directory
user; Lens has no directory, but it has the operator's own labels.

And the week: Palo Alto, UniFi and Firewalla all send a weekly summary. Lens
sends nothing (it writes no mail and needs no mail server), but it can put the
week on one page that prints cleanly on A4 — the report someone reads on
Sunday or hands to whoever asks.

## 2. What gets built

1. **Belongs to** — one more field the operator writes beside name, type, tags
   and note (schema 9, `device_label.owner`). Kept, retained and purged with
   the rest of the label (rule 9: nothing new is observed, and the purge
   already takes labels).
2. **By person** on Who's home: one strip per person, drawn from the devices
   they *carry* — phones and tablets — when they have any, and from all their
   devices when they have none, and the row says which. A desktop left on is not
   a person at home.
3. **Reporting: Lens: Weekly report** (`/ui/lens/report`): the week in one
   column — the one sentence, the internet's uptime and outages, traffic against
   the week before, the heaviest devices, what happened (Events), who was home by
   person, the networks. A Print button; print styles drop OPNsense's menu and
   header and keep each section on one page where it fits.

## 3. Load (rule 7)

The report is the pages it summarises, read once each when it opens — the
same calls those pages make; nothing is added to the collector. By person costs
nothing: it regroups rows the presence report already has.

## 4. Test strategy

Store: the owner field survives a mute and a rename, and goes with purge.
PHP: a person's strip from their phone, not their desktop; a person with only
a laptop is drawn from it and says so; unowned devices stay where they were.
Preview: owners on the seeded household, the report screenshotted and printed
to PDF.
