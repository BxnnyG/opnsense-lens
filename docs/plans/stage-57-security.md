# Stage 57 — What is known about the firewall's packages (Plan and record)

> Systems: S16 · Operator 2026-10-09: "Security — wenn Zero-Day, dann auch so ein
> Wissen mit reinbringen"

## 1. What can be known

A zero-day is, by definition, in no database. What is known about the installed
packages is in FreeBSD's vulnerability database (VuXML): OPNsense's System:
Firmware: Status → *Run an audit* fetches it and runs `pkg audit -F`
(`scripts/firmware/security.sh`, identical on `stable/26.1` and `stable/26.7`,
`PKG=/usr/local/sbin/pkg`).

## 2. What exists (verified)

- `pkg audit -R json-compact` (freebsd/pkg `src/audit.c`): `{pkg_count,
  packages: {name: {version, issue_count, issues: [{"Affected versions",
  description, cve: [...], url}], "reverse dependencies"}}}`. Without `-F` it
  reads `vuln.xml` in pkg's database directory (`/var/db/pkg/vuln.xml`); without
  that file it warns "vulnxml file … does not exist" and prints nothing.
- Parsing the ~20 MB database costs a second or two of CPU.

## 3. What gets built

- The collector runs `pkg audit -R json-compact` on the harvest, only when
  `vuln.xml` or the installed packages (`/var/db/pkg/local.sqlite`) changed since
  the last run, and keeps the answer in `audit.json` beside the store. Never
  `-F`: fetching is OPNsense's button.
- A *Security* tile: no database yet (grey, with where to run the audit),
  known vulnerabilities (warn, by package), nothing known but a database older
  than seven days (warn), nothing known (good); its detail is the database's age.
  Behind the firmware privilege, as Updates.
- On the System page: each vulnerable package with its issues and CVEs.
- The round captures `ls -la vuln.xml` and the raw audit, so the parser meets
  the box's own answer.

## 4. Load (§4.8)

At most one `pkg audit` per change of database or packages, on the harvest
(every 30 minutes); the page reads a small file.
