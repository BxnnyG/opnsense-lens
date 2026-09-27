# Stage 40 — A package feed (Plan)

> Systems: S0 · BACKLOG #7, ROADMAP "package feed" · Planned 2026-09-27 · Decision: §4.68

## 1. The problem from the operator's point of view

Every release since 0.1 has been `make package` and `make upgrade` on the
router itself, from a checkout. Updates should arrive the way OPNsense's own
do: System: Firmware: Updates.

## 2. What core does (verified, stable/26.7)

- `scripts/firmware/check.sh` runs `pkg upgrade -Un` **without `-r`**, so every
  repository configured in `/usr/local/etc/pkg/repos/` takes part, and each
  upgraded package is listed with its `[repository]`. A third-party repository
  therefore delivers updates through System: Firmware — the path community
  repositories already use.
- It reports a repository as `untrusted` (fingerprint mismatch), `revoked` or
  `unsigned` ("No signature found" when fingerprints are expected); a repository
  configured with `signature_type: "none"` is accepted without a check.
- `scripts/firmware/repos/` runs any executable script there when the firmware
  configuration is written: a plugin may ship its own repository's config.
- `Mk/plugins.mk` builds with `pkg create`, so a package needs FreeBSD; with
  `PLUGIN_NO_ABI` it is marked installable on any FreeBSD ABI (Lens ships no
  binaries).

## 3. What gets built — and what does not

1. `.github/workflows/package.yml`: on demand or a `v*` tag, a FreeBSD 14.3 VM
   runs `make package PLUGIN_NO_ABI=yes` and `pkg repo`, and the run keeps the
   feed (`All/os-lens-*.pkg`, `meta.conf`, `packagesite.*`, `data.*`) as an
   artifact. **Verified by running it**, not by reading it.
2. **Not built: publishing.** Where the feed is served from and whether it is
   signed are the operator's (§4.68). Until then, the artifact's `.pkg` is
   installed with `pkg add` instead of building on the box.

## 4. The operator's three decisions

| Decision | Options | Recommendation |
|---|---|---|
| Where it is served | GitHub Pages (needs the repository public, or a paid plan), GitHub Releases (URLs change per release — a repository needs one stable URL), own web server | Pages, if the repository may be public |
| Signed or not | a pkg signing key (RSA) kept as an Actions secret, its public half on the box — or `signature_type: "none"` | signed: an unsigned feed lets anyone who controls the URL push code that runs as root on the firewall |
| Which ABI | per FreeBSD major, or `PLUGIN_NO_ABI` | `PLUGIN_NO_ABI` while Lens has no binaries |

## 5. Load (rule 7)

None on the box beyond what System: Firmware already does: one more repository
to fetch metadata from when it checks.
