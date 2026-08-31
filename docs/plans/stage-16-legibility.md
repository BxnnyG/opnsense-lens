# Stage 16 — Legibility (Plan)

> Systems: S6 · Depends on: stages 5 and 7 · Built 2026-08-30
>
> Runs **before** stages 8 and 9 despite its number (IDs are never renumbered,
> PROCESS). Pulled ahead on the operator's verdict of 2026-08-30.

## 1. The problem from the user's point of view

> "die Seite zeigt viele Infos, aber ist für DAUs wenig hilfreich bis wenig
> einordnenbar, wie im Vergleich z.B. UniFi mit deren Seiten und Filter"

The page is correct and unreadable. Fifty-two rows of identical visual weight,
one font size, no search, no filter, no icons, and a firewall whose twelve
addresses push everything else off the screen. Every number on it is right, and
finding one device takes half a minute of scrolling.

UniFi's advantage is not its data. It is that a person finds one device in three
seconds. That is the whole target of this stage.

## 2. What gets built

1. **Search** over name, MAC, vendor, every address and every interface. The
   haystack is assembled once in `DeviceReport`, not reassembled in the browser
   on each keystroke.
2. **Segment chips** — one per interface, with a count, toggled on and off. Built
   from the data, so a box with three VLANs gets three and box 2 gets nineteen.
3. **Only devices with traffic** — the switch that turns 52 rows into the 20 that
   did something.
4. **A type icon**, from `DeviceType` — the first thing in this plugin that is
   *inferred* rather than observed, and marked as such (§4.29).
5. **A bar for traffic**, scaled to the largest row *currently shown*, so
   filtering to one segment rescales instead of leaving slivers.
6. **Address lists collapse after three**, with the rest one click away.

## 3. What is deliberately not here

- **A per-device detail page.** That is stage 8, and it wants the DNS view and
  the ports list to be worth opening.
- **Grouping the hypervisor's guests** (BACKLOG #19). Search plus the segment
  chips already make box 2's thirty Proxmox rows navigable; real grouping is a
  data-model question (which of them are one machine?) and deserves its own
  stage rather than a vendor-string heuristic bolted onto this one.
- **Server-side filtering.** Fifty devices is not a dataset. It is a list a
  person is trying to find one thing in, and a round trip per keystroke would
  make that worse.

## 4. Test strategy

`DeviceType` is pure and tested against the operator's own vendor strings —
Proxmox, Ubiquiti, Routerboard, Fujitsu, Tuya, Espressif, ASUSTek, Intel, and the
Pixel and iPhone by hostname. Two tests exist only to pin the failure mode: an
unknown vendor must produce a neutral mark, and must not be forced into the
nearest category.

The filtering is layout and lives in the view, which by the standing rule
(PROCESS) decides nothing: it reads `haystack`, `octets`, `interfaces` and
`kind`, all of which are decided in PHP and covered there.

## 5. Risks

- **An icon is a claim.** A wrong one is a small, constant lie in the corner of
  every row. Mitigated by refusing to guess when nothing matches, by the title
  attribute naming the guess, and by stage 6 making it overridable.
- **Client-side filtering has a ceiling.** Unknown where it is; the page reports
  its own timing and the answer will be visible before it is a problem.
