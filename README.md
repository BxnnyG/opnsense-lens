# Lens — an OPNsense plugin

**Status: planning. There is no code yet.** This repository currently holds the
design and the process the plugin will be built under.

## What it is meant to be

OPNsense can tell you what traffic happened and, separately, who is on the
network right now. It never joins the two over time — flow history is
aggregated on the source address and nothing else, and device identity exists
only in the present tense. So the answer to "what was that spike last Tuesday"
is an IP address.

Lens closes that gap: every device gets an identity that survives an address
change, and everything the firewall knows — traffic history, DNS activity,
leases, live throughput — hangs off it. On top of that sit a client profile
page, a Reporting overview, dashboard widgets and a wallboard.

It reads OPNsense's own data through its APIs rather than collecting its own,
and stores only what nothing else keeps: identity over time, the operator's own
names and tags, and computed baselines.

## Where to start reading

| | |
|---|---|
| Why it exists, and what it deliberately is not | [docs/VISION.md](docs/VISION.md) |
| What OPNsense already provides, and the systems being built | [docs/DESIGN.md](docs/DESIGN.md) |
| How work is done here | [docs/PROCESS.md](docs/PROCESS.md) |
| What happens next | [docs/ROADMAP.md](docs/ROADMAP.md) |
| What is worth doing, and an honest assessment | [docs/BACKLOG.md](docs/BACKLOG.md) |

## Relationship to OPNsense

Lens is not submitted to `opnsense/plugins` and is not expected to be —
[docs/DESIGN.md §4.2](docs/DESIGN.md) explains why. It is distributed from this
repository as the package `os-lens`. It does not replace Reporting → Insight,
Health, NetFlow or Unbound DNS, and it does not replace the dashboard: it
supplies widgets to the one OPNsense already has.

Target release: OPNsense `stable/26.7`.

## Licence

BSD 2-Clause, matching the OPNsense plugin collection whose build machinery
this repository uses.
