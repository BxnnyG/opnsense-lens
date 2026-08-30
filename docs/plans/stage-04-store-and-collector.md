# Stage 04 — Store and collector (Plan)

The first stage that keeps anything. It builds S2, and it is taken ahead of the
setup wizard by §4.19 for one reason: **every day this does not exist, data is
deleted that no later effort can recover.**

## 1. The problem from the user's point of view

Nothing on screen changes. That is the point, and it is worth saying plainly
rather than dressing up.

OPNsense keeps per-client traffic at five-minute resolution for **one hour** and
at hourly resolution for **one day** (DESIGN §1.4). After that a device's day is
a single number. So the question "was this normal for that device, at that hour
of that weekday" — the baseline in S8, the timeline in S9, most of what makes
the client profile worth opening — needs weeks of hourly buckets that core
deletes after twenty-four hours.

There is no way to build that later. A month spent on screens first is a month
of hourly history that does not exist and cannot be reconstructed, and the loss
is silent: the daily totals survive, so every chart still draws, just flat and
useless at any zoom worth having.

The same is true of identity. Nothing in OPNsense records that `10.10.20.115`
was `BXY-Pixel-10` yesterday. Attributing yesterday's traffic with today's ARP
table is the failure mode named in PROCESS edge case 2.

So this stage starts the clock. It replaces the hand-run
`tools/lens-observe.sh` (§4.14) that has been standing in since 2026-08-29.

## 2. What already exists (inventory, not guesswork)

- **The harvest interface.** `configctl netflow aggregate fetch <provider>
  <from> <to> <resolution> <key_fields>` — five arguments, which is how core
  calls it itself in `NetworkinsightController::timeserieAction`. It runs
  `/usr/local/opnsense/scripts/netflow/get_timeseries.py` and returns
  `{"<bucket>": {"<key>": {"octets": n, "packets": n, "resolution": n}}}`, with
  empty slices filled in up to now. Verified against `stable/26.7`, 2026-08-30.
- **The identity sources.** `arp -an`, `ndp -an` and `/var/db/dnsmasq.leases`,
  all already read by `tools/lens-observe.sh` and proven over a night of
  snapshots on the operator's box.
- **The cron hook.** `<name>_cron()` in `plugins.inc.d/<name>.inc` returning
  `['autocron' => [<command>, <minute spec>]]`. `security/q-feeds-connector`
  runs `configctl -d qfeeds update` at `*/5` — the pattern to copy.
- **What a night of observation looks like**, from `lens-observations.log`: 13
  MACs, 2 randomised, zero address reuse, one device legitimately holding two
  addresses at once (§4.17). The schema has to fit that, not an idealised
  network.

## 3. What gets built

**A store**, `/var/db/lens/lens.sqlite`, mode 0600 because every row in it
describes a person's behaviour (S14). Schema versioned from the first commit,
with migrations, because a schema change after this ships is a migration whether
or not one was planned for.

**A collector**, `/usr/local/opnsense/scripts/lens/collect.py`, with two duties
and a cron entry each.

*Observe*, every five minutes: `arp`, `ndp`, leases → address observations with
validity windows, not a current-value table. A device holds a **set** of
addresses (§4.17), and an address is only attributable to a device *for the
window in which that device held it*.

*Harvest*, every thirty minutes: hourly per-client buckets out of flowd and into
our own table, before core's twenty-four hour expiry removes them. Thirty
minutes rather than hourly so that a single failed run costs nothing; the
twenty-four hour ceiling means even a day of failures is survivable, and the
harvest re-reads from its last stored bucket rather than assuming it ran.

**The collector calls no `configctl`.** Cron invokes `configctl -d lens collect`,
so the script runs *inside* configd; calling back into it would re-enter the
daemon. It reads `arp`/`ndp`/lease files directly, exactly as
`tools/lens-observe.sh` does, and invokes core's `get_timeseries.py` as a
subprocess rather than through its configd action.

**Retention and purge in this stage, not after it** (BACKLOG #6, S14). A store
that grows without a stated ceiling on a router's disk is a future outage, and
one holding this data without a working delete is a liability. Both ship with
the first row written.

**A disk guard.** Above a stated size the collector stops writing and says so,
rather than filling `/var` on a box whose whole job is staying up.

**One surface change:** a "What Lens has kept" block on the Data Sources page —
devices known, addresses observed, hourly buckets held, oldest bucket, database
size, when each duty last ran. No device list; that is stage 5.

**Chosen over the alternative:** reading `/var/netflow/src_addr_003600.sqlite`
directly. Faster and gives exact buckets without core's resampling, but couples
Lens to a schema that belongs to somebody else and is not part of any interface.
`get_timeseries.py` is what core itself calls.

## 4. Data model

```
schema_version(version)
setting(key, value)                       -- retention days, disk ceiling
device(mac, first_seen, last_seen, hostname, hostname_source)
address_observation(mac, address, interface, first_seen, last_seen)
traffic_hour(bucket, address, direction, octets, packets)
harvest_state(provider, last_bucket)
run_log(duty, at, ok, detail)
```

`traffic_hour` is keyed on **address**, not MAC, because that is all flowd knows.
Joining it to a device happens at read time through `address_observation`, using
the window that covers the bucket — that join is S4 and arrives in stage 7. Doing
it at write time would bake today's answer into yesterday's data.

`device.hostname` records what was observed. Operator-chosen names are a separate
table in stage 6 and never share a column with an observation, so a rename by
DHCP cannot overwrite a name a human chose (S1).

## 5. Surface / output

The block described above, fed by `configctl lens status`. Deliberately dull.

## 6. Test strategy

- **Unit, Python:** the observation window logic — a tuple seen again inside the
  gap extends its window, outside it opens a new one; a device with two
  concurrent addresses produces two open windows, not one flapping between them.
  Harvest bucket selection: resume from the last stored bucket, skip zero-octet
  filler slices, never re-insert a bucket already held.
- **Unit, Python:** parsing of `arp -an`, `ndp -an` and the lease file, against
  the recorded output already in `tools/`.
- **Unit, PHP:** the store-status assessment, same pure-function split as S3.
- **Manual:** the collector's runtime and the database's growth rate on the
  operator's two-core box (§4.8).

## 7. Risks & the way back

| Risk | How it shows | Way back |
|---|---|---|
| The collector runs long enough to matter on a 2-core box | Web interface stutters every five minutes | Runtime is recorded per run and shown. If observe exceeds a second or harvest a few, the interval widens before the work does. |
| `get_timeseries.py` changes shape | Harvest silently stores nothing | Harvest records rows-written per run; a run that stores zero while flowd is fresh is reported, not swallowed. |
| The database grows faster than expected | `/var` fills; the firewall stops being a firewall | Disk ceiling, checked before every write, plus the size on the page from day one. |
| A schema change later needs a migration | Upgrade fails or data is lost | Versioned from the first commit; migrations run before the first read and are tested against the previous version. |
| Observation windows fragment under MAC randomisation | Device count creeps up | §4.17 is provisional and re-read 2026-09-06. Raw observations are kept, so a merge stays reconstructible. |

Rollback is `pkg delete os-lens`, which leaves `/var/db/lens` in place — removing
someone's collected history on deinstall would be its own kind of wrong. The
purge action deletes it deliberately, when asked.

---

## 8. What happened (2026-08-30)

Built as planned. Three details the plan left open, decided while building.

**The harvest never stores the current hour.** `get_timeseries.py` happily
returns the bucket covering right now, still being written. Storing it once and
never revisiting it would freeze whatever had accumulated at the moment the
collector ran — a quiet, permanent undercount. Only buckets whose hour has fully
passed are kept, and the watermark only moves forward.

**Zero-filled slices are not measurements.** Core pads its reply with
`{"octets": 0}` for every dimension in every slice, so that a chart can draw a
continuous line. A device that sent nothing did not send zero bytes; it produced
no record. Storing the padding would have inflated the store and taught every
later average a lie.

**`collect.py` takes `LENS_DB` from the environment.** So the tests drive the
real script against a temporary file. That smoke test is three cases and catches
what unit tests structurally cannot: an import that does not resolve, a
migration that does not apply, an argument that does not parse.

### Result

| Check | Result |
|---|---|
| `tests/gates/run.sh` | style 0/0 · php lint 0 · xml 0 · model 0 · **python 0** |
| `phpunit` | 53 tests, 197 assertions |
| `python3 -m unittest` | 28 tests, including the real script end to end |
| Collector runtime on the box | **owed by the router test** — the page shows it per duty |
| Database growth rate | **owed** — a week of running answers it |

The gates learned Python in this stage; they had been saying "the plugin ships
no Python" since stage 1, which stopped being true here.

---

## 9. What the router test found (2026-08-30)

It works, and it found a modelling error the same evening.

| Measure | Result |
|---|---|
| `observe` | 7 ms · 13 devices, 25 addresses, 25 windows |
| `harvest` | 459 ms · 4932 buckets over 21 hours, all new |
| Store | 0.4 MB after a day → roughly 130 MB a year |

**4932 buckets in 21 hours, for 13 devices.** That is about 235 addresses an
hour, which is far too many to be devices — and the reason is in core's
aggregator. `FlowSourceAddrTotals` writes every flow twice, and on the second
write it *replaces* `src_addr` with the destination:

```
if=if_in,  src_addr=<the device>,  direction=in
if=if_out, src_addr=<the far end>, direction=out
```

So half the table is remote peers, and the only field that separates them from
devices is `if` — which the first harvest did not request. Without it, a phone
and a Google server are the same kind of row, and the attribution in stage 7
would have been built on a table that cannot support it.

Fixed by harvesting `if,src_addr,direction` and by schema 2, which drops the
rows collected without the interface rather than leaving unusable data in place,
and resets the watermark so the next harvest refetches what NetFlow still holds.
Nothing is lost that NetFlow had not already deleted. Devices, hostnames and
address history are untouched — only the traffic table was wrong.

This is the second time in two days that reading the *numbers* rather than the
status caught something no test would have: the tests asserted that buckets were
stored correctly, and they were. They just were not the right buckets.

---

## 10. What the second box found (2026-08-30)

Installed on the operator's other firewall — OPNsense 26.1.9, nineteen
interfaces including `lagg0` VLANs, two WireGuard tunnels and NetBird. The first
`configctl lens harvest` never returned, had to be interrupted, and left
`database is locked` behind it.

Two defects, and the second is the worse one.

**One request, one transaction.** `get_timeseries.py` fills every timeslice for
every dimension key it found. On a box that size the 23 hour cold start builds
one very large object, hands it over as JSON, and is inserted inside a single
transaction — during which the store is locked and nothing else can read it.
Interrupting `configctl` does not stop the script behind it, so the lock
outlived the shell. Fixed by fetching and committing in four hour chunks:
bounded memory, short locks, and a run that dies keeps everything up to its last
chunk.

**A timeout that reported success.** `read_command` caught
`subprocess.TimeoutExpired` and returned an empty string, which became an empty
payload, which became `0 buckets offered, 0 new` — indistinguishable from a
quiet network. The plan for this stage said in as many words that a harvest
storing nothing while flowd is fresh must be reported rather than swallowed, and
the implementation did the opposite. Fixed: the harvest path now raises on a
timeout, a non-zero exit, or an empty answer, and the run is logged as failed.

Also: a reader arriving during a write now waits up to thirty seconds instead of
answering "database is locked".

The lesson is not "add a timeout". It is that **the failure mode a plan calls out
by name is exactly the one to write a test for first** — this one shipped
because the requirement was written down and then implemented from memory.
