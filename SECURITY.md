# Security

Lens runs on a firewall, as root, and writes one firewall rule when asked to.
A flaw in it is a flaw in the firewall.

**Please do not open a public issue for a vulnerability.** Report it privately
through GitHub: *Security* tab → *Report a vulnerability* on
[BxnnyG/opnsense-lens](https://github.com/BxnnyG/opnsense-lens/security). You
will get an answer within a week; a fix is released as soon as it is verified on
a box, and you are credited unless you ask not to be.

In scope: the plugin in `net-mgmt/lens` — its API and privileges, the collector,
the pause rule, the package feed and `tools/install.sh`. Out of scope:
OPNsense itself (report those to the OPNsense project).

The package feed is signed; its public key is
[tools/feed/lens.pub](tools/feed/lens.pub), and `tools/install.sh` refuses a feed
that does not verify against it.
