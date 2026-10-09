# Contributing to Lens

Thank you for looking. Lens is built stage by stage under
[docs/PROCESS.md](docs/PROCESS.md); the short version for a first contribution:

1. **Say what you saw first.** Open an issue with the version (`pkg info
   os-lens`), the OPNsense release, the page and what it showed — a screenshot
   or the API answer (`/api/lens/...`) is worth more than a description.
2. **Every feature has a decision.** Behaviour is decided in
   [docs/DESIGN.md §4](docs/DESIGN.md), each with a number and a date, before it
   is built; a pull request that changes behaviour points at its entry or adds
   one.
3. **Verify against OPNsense, don't guess.** Anything Lens reads from core is
   checked against `opnsense/core` on the branches it supports (26.1 and 26.7);
   say which commit you read.
4. **Lens reads.** It writes to the firewall only for pausing a device
   ([CLAUDE.md](CLAUDE.md) rule 6); a change that writes anything else is a
   decision for the maintainer, not a pull request.
5. **Before you open a pull request:**

   ```sh
   composer install && vendor/bin/phpunit --configuration tests/phpunit.xml
   python3 -m unittest discover -s tests/python
   tests/gates/run.sh          # OPNsense's own style and lint gates, offline
   ```

   A page you changed: look at it in [tools/preview](tools/preview/README.md)
   and attach a screenshot (`node tools/preview/shoot.js`).

Lens describes real people's devices: never put a real MAC, address, hostname
or name in an issue, a fixture or a screenshot — the preview's made-up
household is there for that.
