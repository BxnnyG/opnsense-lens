# Stage 02 — Preflight: what this box can actually tell you (Plan)

Follows stage 1, which proved the plugin reaches the rest of OPNsense. This
stage makes the answer honest. It builds S3's first half and is the direct
consequence of §4.13 (the plugin makes an unconfigured box work) and §4.18 (a
source is only real if its data is fresh). The wizard that *switches sources on*
is stage 3; this stage only tells the truth about what is there.

## 1. The problem from the user's point of view

The page from stage 1 lies, and it lies in the most expensive way: reassuringly.

On the operator's own box it prints **DNS query statistics (Unbound): answering**
in green. Unbound is installed, configured, running, and answers when asked. It
also resolves nothing at all — dnsmasq does that — and its statistics database
had not been written for ten hours. A user reading that line would conclude the
DNS view is available, wait for it, and find an empty page.

The same shape of lie was already caught once this week outside the plugin. The
operator had NetFlow switched on since 2026-08-23 and assumed traffic history
was being collected. It was: for `lan`, `wan` and `opt1` — MGNT, WAN and the
management NIC. The eight VLANs the operator actually cares about, including
HOME, IOT and GUEST, were not captured at all. Nothing anywhere said so. A week
of data existed about the segments nobody asks questions about.

So: a green dot is a promise. This stage makes the page only promise what it can
keep, and — where it cannot — say what is missing, why it matters, and what
would fix it.

## 2. What already exists (inventory, not guesswork)

- `SourceProbe` (stage 1) turns a configd reply into answered/silent and knows
  the five commands, all verified against `stable/26.7`. Its `interpret()` and
  the dotted-action-name guard stay; this stage adds a layer above it, it does
  not replace it.
- **Retention, fixed in core** (DESIGN §1.4): interface totals 30 s/1 day →
  daily/1 year; per-client totals 5 min/**1 hour** → daily/1 year; per-client
  *details* **daily only, 62 days**. This is data the page can state without
  measuring anything.
- **Core model classes**, so config is read typed rather than by poking XML:
  `\OPNsense\Diagnostics\Netflow` (`capture/interfaces`, `capture/egress_only`,
  `collect/enable`) and `\OPNsense\Unbound\Unbound` (`general/stats`).
- **Service state** via configd: `netflow collect status`, `netflow aggregate
  status`, `unbound status`, `dnsmasq status`, `kea status`. All reply in the
  `<name> is running as pid <n>` / `is not running` form.
- **Freshness** from the filesystem: `/var/netflow` (aggregates),
  `/var/unbound/data/unbound.duckdb`, `/var/db/dnsmasq.leases` — every path
  verified against core on 2026-08-30.
- **Interface names and descriptions** from `config.xml`, which is how
  `opt3` becomes `HOME` on screen. Nobody thinks in `opt3`.
- The hand-run `tools/lens-preflight.sh` is the executable specification for all
  of the above (§4.14). Whatever it checks, this page checks.

## 3. What gets built

`SourceReport`, a pure assessment function, plus the controller that feeds it.

**The split matters.** The controller gathers raw facts — booleans, counts,
timestamps, interface lists — and hands a plain array to
`SourceReport::assess()`, which decides what they mean. Assessment has no
`Backend`, no filesystem, no config: it is a function from facts to verdicts, so
every verdict is testable against a recorded fixture without a router (DESIGN
§0). The two rules of this project that keep being right — interpretation in one
place, and prove it without the box — both fall out of that split.

**Three verdicts, never two.** `ready`, `degraded`, `absent`. The middle one is
the whole point: a source that is present but not usable is the case stage 1
could not express, and it is the common case in the wild.

Each source reports: verdict, a one-line headline, the **cause** in a sentence,
and the **action** that would change it. A red state with no sentence is a
support request waiting to happen (VISION, point 3).

**Coverage, for NetFlow only.** Captured interfaces are shown by description
against every routed interface on the box, so "eight of your eleven segments are
invisible" is a thing the page can say. This is the check that would have caught
the operator's own configuration a week earlier.

**Depth, stated not guessed.** The page prints core's actual retention per
aggregate, plus the last aggregation time. It does *not* claim to know how far
back records really go — that depends on when capture started, and measuring it
means opening the aggregate databases. That measurement is a backlog item, and
the page says depth is bounded by both facts rather than implying one.

**Per-probe timing.** Stage 1 cost two seconds and nobody knew which call spent
them. Each probe is timed and the total is shown, so the load budget (§4.8) is a
number on the page rather than a note in a document.

**Chosen over the alternative:** enriching stage 1's booleans in place. Rejected
— the verdict logic would have grown inside the controller, next to the
`Backend` calls, and become untestable exactly as it got interesting.

## 4. Data model / interfaces

No persistence. Still no store, no model XML, no migration — stage 4 opens that
with the retention question already answered (S14, BACKLOG #6).

One endpoint, `/api/lens/sources/report`, replacing stage 1's `probe`. Read-only.
It runs the five configd probes, five service-status calls, three `stat`s and two
config reads. Every call is bounded; nothing polls; the page asks once on open.

Fixtures recorded for each new reply shape, per BACKLOG #9 — including the
service-status strings, which are parsed and therefore a boundary.

## 5. Surface / output

One table, one row per source: source, verdict, headline, cause, what it makes
possible. Under it, two blocks — NetFlow coverage by interface description, and
the retention table — plus the timing line.

Both pages keep sharing it. `Reporting → Lens` still has nothing of its own to
show; it will get its own content when there are devices to show, not before.

## 6. Test strategy

- **Unit:** `SourceReport::assess()` against recorded fact sets. The cases that
  matter are the ones this week produced: Unbound configured + running + stale;
  NetFlow on but capturing three of eleven interfaces; aggregator stopped while
  the collector runs; no DHCP server at all; a lease file that exists but is
  empty. Each asserts the verdict *and* that a cause and an action are present —
  a verdict without a sentence fails the test.
- **Unit:** service-status parsing, both forms, plus an unparseable one.
- **Manual, unavoidably:** the numbers on the operator's box are only checkable
  by someone who knows what the box does.

## 7. Risks & the way back

| Risk | How it shows | Way back |
|---|---|---|
| Service-status strings differ per daemon | A running service reported as stopped | Parse defensively: "not running" wins over "running", and an unrecognised reply is `unknown`, never `ready`. Fixture per daemon. |
| The report gets slower than stage 1's two seconds | Page feels broken | Timing is on the page. If the total passes ~3 s, the slow probe is dropped or deferred and that is recorded in the operations notes. |
| Verdict logic drifts from `tools/lens-preflight.sh` | Two tools disagree about the same box | They are meant to agree; the script is the spec (§4.14). Any rule added here is added there. |
| A "degraded" verdict is wrong and nags | Trust in the page erodes | Every degraded state names its cause, so a wrong one is visibly wrong rather than mysteriously amber. |

Rollback is `pkg delete os-lens`. Nothing is written outside the package; the
authority to switch sources on does not arrive until stage 3.

---

## 8. What happened (2026-08-30)

Built as planned. One thing the plan did not anticipate.

**Stage 1's `SourceProbe` went dead as this was written.** Its `probes()` list
and `interpret()` were replaced by the fact-gathering controller, and leaving
them in would have been two ways to ask the same question. It was rewritten
instead into what it should always have been: the configd boundary layer, and
nothing else — the exact commands as a named list, plus the three ways a reply is
read (`serviceState`, `countOf`, `version`). The dotted-action-name guard moved
with the commands, so the test that protects against this project's most
expensive mistake still protects the thing that can make it.

### Result

| Check | Result |
|---|---|
| `tests/gates/run.sh` | style 0 errors / 0 warnings · php lint 0 · xml 0 · model 0 |
| `phpunit` | 32 tests, 151 assertions, all passing |
| The Unbound defect | covered by `testUnboundRunningButNotResolvingIsNotReady` |
| The coverage defect | covered by `testPartialCoverageNamesWhatIsInvisible` |
| Verdict against the operator's real numbers | **owed by the router test** |
| Report timing | **owed by the router test** — the page now prints it |

Two tests are the point of this stage, and both encode a way the page was
wrong this week rather than a rule someone invented: that a resolver which runs
but has recorded nothing for ten hours is not ready, and that capturing three of
eleven interfaces must name the eight that are invisible.

### Cost

Seven configd calls in the common case, up from five, and `unbound qstats
totals` is gone — freshness now comes from the database's mtime, which costs a
`stat`. Whether that nets out faster or slower than stage 1's two seconds is a
measurement, not a guess, and the page carries it.

---

## 9. What the router test found (2026-08-30)

Both menu roots appear — `Services → Lens → Data Sources` and `Reporting → Lens`.
Risk 1 of stage 1 is closed, and Lens is the first plugin in the collection to
place an entry under `Reporting`.

The report takes **358–597 ms**, against stage 1's two seconds, with *more*
calls. The one that cost the difference was `unbound qstats totals`, dropped for
an unrelated reason. Slowest now: `unbound status` 149–187 ms, `interface list
arp json` 1–123 ms depending on its 30 s configd cache.

**And it found a real defect.** `0.1_3` reported "10 leases from dnsmasq" and
"Unbound is the only resolver here" on the same page. See §4.21: the fix was
structural, not a missing line, and `tests/SourceFactsTest.php` now starts from
recorded configd output so a wiring mistake cannot pass again.

Verdicts on the operator's real box, `0.1_4`: NetFlow ready (11 of 11
interfaces), identity ready (21 addresses), DHCP ready (10 leases), DNS
unavailable-by-choice.
