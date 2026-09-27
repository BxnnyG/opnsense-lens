# Stage 37 — Find anything, filter everything (Plan)

> Systems: S11, S6 · BACKLOG #36 · Planned 2026-09-27 · Decision: §4.65

## 1. The problem from the user's point of view

"Which device is 10.10.20.62" means opening Devices and typing. "Show me only
IOT" works on Devices and nowhere else: Who's home, Events and DNS each start
from the whole network again. Palo Alto's ACC solves the second with one global
filter that every widget follows; Linear, GitHub and UniFi solve the first with
a command palette.

## 2. What core has (verified, stable/26.7)

A **menu search** in the top bar (`#menu_search_box`, `/api/core/menu/search/`)
finds pages, not devices. Core's global shortcuts are `a`, `f` and `h` without
modifiers, outside inputs (`opnsense_ui.js`, `initGlobalOpenShortcuts`);
Ctrl-K and `/` are free.

## 3. What gets built

1. **Palette** (`Lens.palette`, every Lens page): Ctrl-K, ⌘K or `/` opens it.
   It searches devices — name, MAC, every address, vendor, tags, network — and
   Lens's pages and networks. Enter opens the device page or the page. The index
   is one call (`/api/lens/devices/index`), named by DeviceReport like every
   page, kept in the tab for five minutes.
2. **Filter** (`Lens.filter`): networks and tags as pills above the page, in
   the address (`?segment=`, `?tag=`) and carried to the next Lens page in the
   tab. Devices, Who's home, Events and DNS follow it; the bar shows only on
   pages that do, so it never claims to filter what it does not. Events about
   the line and the internet stay — they are about every network.
3. Rows gain what the filter needs (interfaces and tags) in PHP; matching is a
   set test in JavaScript, which is layout, not interpretation.

## 4. Load (rule 7)

The palette's index: three configd calls on first use per tab, then none for
five minutes. The filter costs nothing — it narrows what the page already has.

## 5. Test strategy

PHP: the index names devices as DeviceReport does, carries every address and
MAC of a folded phone, and lists the pages. The views gate checks the scripts;
the preview screenshots the palette open and a filtered page.
