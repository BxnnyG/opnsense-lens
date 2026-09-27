# Page review: Lens against UniFi, Palo Alto, Zenarmor and Firewalla (2026-09-27)

> Asked by the operator: *"ein Report was noch geil wäre, wie man die Seiten
> verbessert — nimm dir ein Beispiel an Zenarmor, UniFi, Palo Alto."*
>
> Written against `0.17_1`. Every idea below names the data it would stand on,
> because in this project the data decides what is possible and the screen only
> decides how it looks. [VISION.md](VISION.md) already carries the Zenarmor
> comparison of 2026-09-14 and the self-check of 2026-09-24; most of what they
> proposed is built (range picker, drill-down, export, tags as a filter, who's
> home, heatmap, life story, one sentence, `/metrics`, latency and loss). This
> review starts where they stopped.

## 0. The uncomfortable part first

Eighteen stages — every page this review talks about — have not been clicked on
a router since 2026-08-31. Improving pages nobody has looked at on the box is
designing in a text editor again, which §4.47 and §4.48 were both about. **The
router round ([ROUTER-ROUND.md](ROUTER-ROUND.md)) comes before any of this.**
Several items below are phrased as "check first" for that reason.

Two things were found while reading the views for this review, and they belong
in that round rather than in a stage:

- **No view has a single `@media` rule.** UniFi and Firewalla are phone-first;
  the operator will open Lens on a phone. Flexbox with `flex-wrap` may carry the
  dashboard, but the device table, the presence strips and the heatmap have never
  been seen at 390 px. *Check first.*
- **Colours are hard-coded** (`#5cb85c`, `#f0ad4e`, `#d9534f`, `#d94f00`) rather
  than taken from the theme. OPNsense ships a dark theme; nobody has opened Lens
  in it. *Check first.*

## 1. What each of them does best, in one line

| Product | The one idea worth studying | Can Lens have it? |
|---|---|---|
| **Palo Alto — ACC** | Click anything, it becomes a filter; *promote* a filter and every widget on every tab follows it | **Yes.** Pure view work over data already kept |
| **Palo Alto — IoT Security** | Every device identity carries a **confidence** (high 90–100, medium 70–89, low <70), and only high-confidence identities are acted on | **Yes**, for the one thing Lens infers (the device type, §4.29) |
| **UniFi — Client page** | One page per client that tells its whole story, reachable from everywhere | **Built** (stage 26). What is missing is *what it talked to* |
| **UniFi — Insights › Flows** | A table of completed sessions: source, destination, port, protocol, per device | **Partly.** Daily per-device destinations exist in core for 62 days (§1.4) |
| **UniFi — Dashboard** | Internet health first: WAN, latency, uptime, speed test | **Built** (stages 28, 29), except the speed test — see §5 |
| **Zenarmor — Reports** | Every chart drills into a sessions explorer that keeps the filter | **Partly** (stage 19); the "explorer" half needs live state, §3 |
| **Zenarmor — Live Sessions** | What is happening *now*, per device, with a block button | The *now* half is a read of pf's state table — **check the core endpoint first** |
| **Firewalla — Alarms** | "Abnormal upload" and "new device" as events you can mute per device, with a sensitivity setting | **Yes.** The detection exists; the *feed* and the *upload* split do not |

## 2. With data Lens already keeps — cheapest, most of the effect

Ranked by effect on the person reading, then by cost. None of these needs a new
source, a new permission or a new packet.

### 2.1 One filter bar for every page (Palo Alto ACC) — BACKLOG #36

**Today:** each page has its own filters — range on four pages, network and tag
chips on Devices, a network on Networks. Clicking a device on the dashboard opens
its page; clicking a network opens Devices filtered. There is no way to say
"show me everything about the IoT VLAN this week" and have the dashboard, Who's
home and Networks all follow.

**Take from ACC:** a filter is a pill in the page header — `network: IOT ×`,
`tag: kids ×`, `7 days ×` — carried in the query string (stage 18 already does
this for the range), so every Lens link keeps it. Any chip, bar or legend entry
can add itself. ACC's *promote to global* is the key move: a click on a donut
slice filters that card; a second gesture filters the page.

**Stands on:** `Window` (range), the chip builder Devices already has, and
DeviceReport's `haystack`. **Decision needed:** whether a filter that a page
cannot honour (a tag on the internet panel) is dropped silently or shown
greyed — ACC shows it; §4.43 says show it.

### 2.2 What happened while I was away — an event feed (Firewalla, UniFi) — BACKLOG #37

**Today:** Lens *detects* five kinds of event and shows each only in its own
corner, and only in the present tense: a new device (§4.34), an unusual day
(§4.50), an outage (§4.57), a degraded gateway (§4.56), identity fragmenting
(§4.36). A device that was unusual on Tuesday is not mentioned on Thursday.

**Take from Firewalla and UniFi's notifications:** one chronological list —
"Tue 14:05 · new device `Galaxy-A55` on GUEST", "Wed · NAS moved 4 GB against a
usual 300 MB", "Wed 03:10–03:25 · internet unreachable, 15 min" — each opening
the page that proves it. Firewalla lets alarms be muted; Lens should go one
step further and mute per device, so a known noisy device stops appearing
without the threshold changing for everyone.

**Stands on:** `device.first_seen`, `probe_sample` rounds, `gateway_sample`,
and daily totals — every past day's verdict can be recomputed from what is kept.
This is most of S9 (the correlation timeline) without a new source. **Cost:** a
read that scans N days of daily totals; bounded by the range picker.

### 2.3 Upload, not just volume (Firewalla "abnormal upload") — BACKLOG #38

**Today:** the baseline judges total bytes. A laptop downloading an update and
a camera sending 4 GB *out* are the same kind of event to it.

**Take from Firewalla:** judge upload separately. For a firewall operator the
upload is the security-relevant direction — exfiltration, a compromised
camera, a backup to the wrong place — and downloads are almost always benign.
Show both verdicts, lead with upload.

**Stands on:** `traffic_hour.direction`, already stored; `daily_totals` would
group by direction as well. The three guards and their settings (§4.58) apply
unchanged, per direction.

### 2.4 "Compared with last week" on every figure — BACKLOG #39

**Today:** every figure is absolute. "9.4 GB in 24 hours" does not say whether
that is a lot.

**Take:** a small delta beside each dashboard tile and network card — "+38 %
on last week" — and a faint previous-period line under the network chart. It is
a common dashboard device rather than any one product's idea, and the cheapest
way to make a number mean something.

**Stands on:** the same queries with the window shifted back one period. **The
rule that must come with it** (§4.43): when the previous period is not fully
covered by what Lens has collected, the delta is not shown — a comparison with
half a week reads as a trend and is not one.

### 2.5 A household view of Who's home (Apple Home, UniFi) — BACKLOG #40

**Today:** presence is per device. A person owns a phone, a watch and a laptop.

**Take:** group presence by an *owner* — the operator's tag is enough, no
directory needed (the Zenarmor comparison already argued this) — and draw one
strip per person where any of their devices was present, with the devices
underneath. The question the Apple user has is "is Anna home", not "is
`A55-von-Karin` associated".

**Stands on:** stage 6's tags and stage 24's spans; the merge rule already
exists (two addresses at once are one presence, §4.52 — two devices of one
person at once are one presence by the same logic).

### 2.6 Say how sure the guess is (Palo Alto IoT Security) — BACKLOG #41

**Today:** the device-type icon is the only inference Lens shows, and it is
fenced (§4.29): a tooltip names the guess, an unknown vendor gets a neutral
mark. It is either a guess or not.

**Take from Palo Alto:** a confidence with its reason. *Certain* — the
operator set the kind, or it is the firewall's own address. *Likely* — the
hostname matched (`iPhone`, `tasmota-`). *Vendor only* — `Proxmox Server
Solutions GmbH` says it is a VM and nothing about what it does. Show the
certainty, not a percentage: Lens has no model to produce one, and a number
would claim precision it does not have.

**Stands on:** `DeviceType` already knows which rule matched.

### 2.7 A command palette (UniFi's search, stage 15 / S11)

**Today:** stage 15 is planned and not started. A device is found by opening
Devices and typing.

**Take:** `Ctrl-K` anywhere under Lens, typing a name, MAC, address or tag,
enter opens the device page. The haystack is already built once in PHP
(stage 16). Cheap, and it is the feature power users notice first.

## 3. Needs a read Lens does not do yet — verify against core before planning

Each of these stands on a core endpoint or file that is **not** in DESIGN §1's
inventory. By rule 2 none of them is designed until the call has been run on a
box and its output saved as a fixture.

### 3.1 What a device talked to (UniFi Flows, Zenarmor Reports) — BACKLOG #31, sharpened

`FlowSourceAddrDetails` holds `src_addr, dst_addr, service_port, protocol` per
day for 62 days (§1.4). Harvested daily and attributed with the same
bucket-time join, it becomes the device page's missing card: **top
destinations and services**, e.g. "`camera-garden` talks to one address on
8883 every day". Daily, not hourly — that is core's resolution, and the card
must say so. Reverse DNS for the far end is a second question with its own
load cost. *This one is in the inventory already; it is the next data stage.*

### 3.2 What it is doing right now (Zenarmor Live Sessions)

pf's state table answers "who is this device talking to at this moment". Core
exposes it under Diagnostics: Firewall: States. Joined onto the device's
current addresses it is a live panel on the device page, refreshed on demand,
never on a timer. **Check first:** the endpoint, whether it filters server-side
by address (a full state dump on a busy box is not a page-load read, S13), and
its shape. Read-only; Zenarmor's block button is not part of it (§4.9, #33).

### 3.3 What was blocked (Palo Alto "Blocked Activity")

The firewall log has every packet a logging rule dropped, and OPNsense's
default deny rule logs unless that was switched off (check on the box). Per
device, "47 connections to 23/tcp from outside were blocked" on the camera's
page is exactly the sentence Palo Alto's blocked tab is built around. **Check
first:** how core reads the live filter log through configd, and how far back
it reaches — it is a log, not a store, and Lens would have to harvest it like
NetFlow to keep any history.

### 3.4 DNS (stages 11 and 20) — still one command away

Unchanged since 2026-08-30: `configctl unbound qstats totals 10` on the second
box. It is in the router round.

## 4. Smaller things, to look for in the router round

- **Every number with its period.** "9.4 GB" and "9.4 GB · 24 h" are different
  sentences once a range picker exists; check each tile and card says which.
- **An empty card says why inside itself**, as the internet panel and the line
  card do — not as a banner above the page.
- **The wallboard could cycle** (S10's original plan): dashboard, who's home,
  networks, one per 20 seconds. None of the three products has a wallboard;
  this one is Lens's own.
- **Keyboard:** `/` focuses search on Devices, `←`/`→` steps the range. Costs
  nothing.

## 5. Deliberately not — and why, so it is not re-proposed

| Idea | Seen in | Why not |
|---|---|---|
| Application names and categories ("Netflix", "Gaming") | Zenarmor, UniFi, Palo Alto | needs DPI; declined in VISION |
| Risk scores | Palo Alto IoT | Lens has no vulnerability or behaviour model to score with; a number without one is decoration that looks like analysis |
| A topology map of switches and APs | UniFi | the firewall sees VLANs, not cables; a VLAN tree is possible (Networks already is one), a topology is not |
| Speed test | UniFi | hundreds of megabytes through the line per run, far beyond §4.57's bound. If ever, only on a button, with the cost stated first, and never scheduled |
| Block / pause from a report | Zenarmor, Firewalla, UniFi | #33: a different product with a different failure mode |
| A cloud console | Zenarmor, UniFi, Firewalla | Lens's data never leaves the box; that is a feature |

## 6. Suggested order, after the router round

1. **2.3 upload** and **2.4 comparisons** — both small, both change how every
   existing number reads.
2. **2.2 event feed** — the "what happened while I was away" page, the one
   thing every product in this list has and Lens does not.
3. **2.1 one filter bar** — the largest structural improvement; worth doing
   once the pages have stopped moving.
4. **3.1 destinations per device** — the next data stage, already inventoried.
5. **2.5 household view**, **2.6 confidence**, **2.7 palette** — cheap, in any
   order.
6. **3.2 / 3.3** — only after their endpoints have been run on a box.

## Sources

Read on 2026-09-27; the vendor pages themselves were not reachable from the
build environment, so these are the search results and summaries they came
from, and each claim above is kept to what they state.

- Palo Alto Networks — [ACC Filters](https://docs.paloaltonetworks.com/pan-os/11-0/pan-os-admin/monitoring/use-the-application-command-center/acc-filters),
  [Working with Filters — Local and Global](https://docs.paloaltonetworks.com/pan-os/10-1/pan-os-web-interface-help/acc/acc-actions/working-with-filterslocal-filters-and-global-filters),
  [Use Case: ACC — Path of Information Discovery](https://docs.paloaltonetworks.com/ngfw/administration/monitoring/use-the-application-command-center/use-case-accpath-of-information-discovery)
- Palo Alto Networks — [Device Security solution](https://docs.paloaltonetworks.com/iot/getting-started/iot-security-solution),
  [IoT Devices › Asset Inventory](https://docs.paloaltonetworks.com/ngfw/help/11-1/monitor/monitor-iot-devices/monitor-iot-devices-asset-inventory),
  [Risk Assessment](https://docs.paloaltonetworks.com/iot/administration/detect-iot-device-vulnerabilities/iot-risk-assessment)
- Ubiquiti — [Traffic Flows and Traffic Logging in UniFi Network](https://help.ui.com/hc/en-us/articles/32201256219799-Traffic-Flows-and-Traffic-Logging-in-UniFi-Network),
  [UniFi Gateway — Traffic and Device Identification](https://help.ui.com/hc/en-us/articles/12570783535383-UniFi-Gateway-Traffic-and-Device-Identification),
  [UniHosted: viewing device history](https://www.unihosted.com/blog/how-to-see-device-history-in-unifi-controller),
  [UniHosted: speed test](https://www.unihosted.com/blog/how-to-run-a-real-time-speed-test-from-your-unifi-controller-and-what-it-means)
- Zenarmor — [Reports overview for OPNsense](https://www.zenarmor.com/docs/opnsense/reporting-analytics/reports-overview),
  [Live Sessions Explorer on OPNsense](https://www.zenarmor.com/docs/opnsense/reporting-analytics/live-session-explorer),
  [Release notes](https://www.zenarmor.com/docs/support/release-notes)
- Firewalla — [Abnormal Upload Alarms tutorial](https://help.firewalla.com/hc/en-us/articles/360020926913-Abnormal-Upload-Alarms-Tutorial),
  [Manage Alarms](https://help.firewalla.com/hc/en-us/articles/360006083334-Manage-Alarms)
