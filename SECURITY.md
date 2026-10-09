# Security

Lens runs on a firewall, as root, and writes one firewall rule when asked to.
A flaw in it is a flaw in the firewall.

**Please do not open a public issue for a vulnerability.** Report it privately
through GitHub: *Security* tab → *Report a vulnerability* on
[BxnnyG/opnsense-lens](https://github.com/BxnnyG/opnsense-lens/security). You
will get an answer within a week; a fix is released as soon as it is verified on
a box, and you are credited unless you ask not to be.

## When a vulnerability is known

1. **Confirmed privately** on a box, with a fix and a test that would have
   caught it.
2. **Released at once**: a patched version is tagged, which builds the signed
   feed and a GitHub release in one run; firewalls that use the feed see it
   under System: Firmware: Updates, and Lens's own *Updates* tile says an
   update is waiting.
3. **Published** as a GitHub Security Advisory on this repository — what is
   affected, from which version, what an attacker needs, the fixed version, and
   what to do meanwhile (for example: remove the Lens privileges from users
   who should not have them, or switch off the API until updated). A CVE is
   requested for anything exploitable beyond an administrator's own session.
4. **Noted** in the release notes and in `pkg-descr`, so the fix is visible
   where updates are read.

Watch the repository (*Watch → Custom → Security alerts*) to hear of an
advisory the moment it is published.

## What Lens tells you about the rest of the firewall

A zero-day is, by definition, in no database yet; Lens cannot know of it.
What is known Lens shows: the *Security* tile on Reporting: Lens: System lists
installed packages that FreeBSD's vulnerability database (VuXML) names, and how
old that database is on this box — read locally with `pkg audit`, never
fetched by Lens. Fetching a fresh database and auditing stays OPNsense's own
button, System: Firmware: Status: *Run an audit*.

## Scope

In scope: the plugin in `net-mgmt/lens` — its API and privileges, the collector,
the pause rule, the package feed and `tools/install.sh`. Out of scope:
OPNsense itself (report those to the OPNsense project).

The package feed is signed; its public key is
[tools/feed/lens.pub](tools/feed/lens.pub), and `tools/install.sh` refuses a feed
that does not verify against it.
