# Stage 31 — Design (Plan)

> Systems: S5, S6, S7, S10, S3 · Depends on: every page since stage 16 · Planned 2026-09-27
>
> Decision: §4.59.

## 1. The problem from the user's point of view

> "ich wäre jetzt erstmal für design upgrade" — the operator, 2026-09-27.

For the first time every page was looked at in a browser — through
`tools/preview`, which renders the real views against Lens's real controllers,
a seeded store and core's own theme CSS — at desktop width, in core's dark
theme, and at 390 px. What that showed, and what reading the code never did:

- **Phone:** four pages scroll sideways. Devices is 724 px wide at 390 px; the
  dashboard heatmap and the device page overflow too. No view has a single
  `@media` rule.
- **Dark theme:** it mostly works, because the cards are core's
  `content-box` — but core's dark theme keeps light-mode borders
  (`#E5E5E5` on `#101218`), so every Lens card sits in a bright frame, and
  every Lens colour is hard-coded for a white surface.
- **The same thing has two names.** Networks and the dashboard say `HOME`;
  Devices, its chips and the device page say `vtnet1_vlan20`. The names exist
  (`SegmentsController::names()`); three pages were never handed them.
- **Charts without axes.** The network chart and a device's traffic chart have
  no time labels; the only date on a device chart is `9/27/2026, 12:00:00 AM`.
- **Visible defects:** the "This firewall" card draws three meters with no
  label and no value; the line card's title breaks into two columns; the
  networks donut colours slices by rank, in shades of the same orange that
  means "sent" in the chart above it; Networks prints its figures in a
  monospace face; Data Sources is the one page without cards, and its first
  sentence still promises a fix button that has shipped.

Each of the eight views carries its own `<style>` block — 420 lines between
them, the same card, tile and chip written several times, slightly differently.

## 2. What gets built

1. **One stylesheet, `www/css/lens.css`**, served by core at `/ui/css/lens.css`
   (`alias.url "/ui/" => "/usr/local/opnsense/www/"`, verified against
   `webgui.inc` on stable/26.7) and linked the way core's own views link theirs.
   Tokens for both themes; the pieces every page uses — page intro with the
   range picker, card and card head, stat tiles, chips, tables, meters, status
   words, chart ink, tooltip — written once.
2. **One small script, `www/js/lens.js`**, for layout only: which theme is
   showing (core swaps stylesheets rather than setting a class, so Lens reads
   the surface it is drawn on), a tooltip, and locale-aware time labels.
3. **Colour by job** (dataviz method, validated with its script against core's
   real surfaces, `#ffffff` light and `#101218` dark):
   - *traffic / received* blue, *sent by your devices* orange — the pair passes
     every check in both themes (worst CVD ΔE 26.8 dark);
   - *magnitude* (heatmaps) one blue ramp;
   - *status* good / warning / critical, never alone — always a word or icon;
   - orange stays the accent for controls, as in core.
   Blue/violet, the UniFi pair, was tried first and **fails** in the dark theme
   (protan ΔE 1.9) — recorded so it is not tried again.
4. **Phone layouts** at ≤ 767 px: tiles two across, cards one across, the device
   table as one card per device, charts that scroll inside their card if they
   must rather than moving the page.
5. **The defects above**, each fixed where it lives: segment names passed to
   DeviceReport, DeviceProfile and PresenceReport; axes on the two time charts;
   the donut becomes a bar per network; the system card's meters get their
   labels back; Data Sources gets cards.

## 3. The decisions, not the drawing

- **Inside OPNsense, not on top of it.** Core's font, core's cards, core's
  accent. Lens adds tokens for what core has none of (chart ink, status), and
  overrides core only where core is wrong for Lens (the dark border).
- **Interpretation stays in PHP** (§4.21). The segment name is decided in
  DeviceReport, not looked up in a view; `lens.js` formats a time label and
  nothing else.
- **No new dependency.** Charts stay hand-drawn SVG (stage 8's reason still
  holds); `lens.css` and `lens.js` are plain files.

## 4. Edge cases walked

0. Warnings that never go green: none added.
1. No data: every empty card keeps its sentence; the preview seeds an empty
   store too, and both are looked at.
2. Identity: untouched.
3. Load: two static files, cached by the browser; no new query. The segment
   names are one read of `config.xml` per request, as Networks already does.
4. Disk: none.
5. Privacy: none.
6. Untrusted strings: the tooltip writes with `textContent`, like every view.
7. IPv6: untouched.
8. Packaging: two new files under `src/opnsense/www/`, which installs into
   `/usr/local/opnsense/www/` — the same tree `www/js/widgets/Lens.js` already
   ships in.

## 5. Test strategy

- PHP: segment names reach DeviceReport rows, the search haystack, DeviceProfile
  and PresenceReport; an interface without a name falls back to its device name.
- The rest is layout, and layout is tested by looking: `tools/preview/shoot.js`
  before and after, three views per page, and the report of any page that scrolls
  sideways must be empty at 390 px.
- `node --check` over every view's script, as the gates already do for the widget.

## 6. Risks & the way back

- **A restyle can hide a number.** Every page is compared before/after, and the
  figures on each must be the same figures.
- **Core's theme may change under us.** Lens reads core's surfaces rather than
  copying its hex values, except where core has none.
- **Still not on a router.** The preview is core's CSS and Lens's code, not a box:
  lighttpd, the real menu and a real browser on a phone are the router round's.
