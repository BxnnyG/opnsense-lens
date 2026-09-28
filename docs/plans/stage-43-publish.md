# Stages 42 and 43 — One line to install, and a feed to install from (Plan)

> Systems: S0 · Operator's request 2026-09-27 · Decision: §4.68 (decided now)

## 1. The request

"A one-liner in the README that installs, updates and uninstalls", and the
feed published "with the stuff you mentioned": GitHub Pages, signed, `os-lens`.

## 2. What gets built

1. `tools/install.sh`, POSIX sh (root's shell is csh, so it is always piped to
   `sh`): `install`, `update`, `uninstall [--purge]`, `source`, `status`.
   It writes `/usr/local/etc/pkg/repos/Lens.conf` with `signature_type:
   "pubkey"` and fetches the public key **from the repository**, never from the
   feed. It replaces an `os-lens-devel` built on the box. Uninstall keeps
   `/var/db/lens` unless `--purge`. Tested under dash against stub `pkg`,
   `fetch` and `configctl`.
2. The workflow signs with the secret `LENS_PKG_KEY`, refuses a key that is not
   the one `tools/feed/lens.pub` belongs to, proves the feed with `pkg update`
   against that public key inside the FreeBSD VM, and on a tag or `publish:
   true` deploys it to `https://bxnnyg.github.io/opnsense-lens/feed`.
3. A one-page site beside the feed saying what it is and how to install.

## 3. What only the operator can do

| Step | Where | Why not the agent |
|---|---|---|
| Generate the key pair | on the firewall or a PC: `openssl genrsa -out lens-repo.key 4096 && openssl rsa -in lens-repo.key -pubout` | a private key the agent made would have passed through a chat log |
| Add the private key as secret `LENS_PKG_KEY` | GitHub → Settings → Secrets and variables → Actions | the agent has no access to secrets |
| Put the public key in `tools/feed/lens.pub` | paste it to the agent, or commit it | — |
| Switch Pages on | GitHub → Settings → Pages → Source: *GitHub Actions* | a repository setting, not a file |

Then a tag (`v0.28`) or the workflow with `publish: true` publishes.

## 4. What signing does and does not protect

It protects the packages against anything between the build and the box —
the Pages host, a mirror, a cache — independently of TLS. It does not protect
against someone who controls this GitHub account: they could change the
workflow and use the secret. The account's second factor is that protection.
