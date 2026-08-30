# Fixtures

Recorded output, not invented output. Every file here was produced by a real
command on the operator's OPNsense 26.7.1_1 on 2026-08-30, or is the exact shape
core's own code produces.

| File | Where it came from |
|---|---|
| `dnsmasq-leases.json` | `configctl dnsmasq list leases`, trimmed to two records |
| `arp.json` | the shape `configctl interface list arp json` returns; the three rows are real observations, including one device holding an address in two VLANs at once (DESIGN §4.17) |
| `version-lens.json` | the shape `Templates/version` renders into `/usr/local/opnsense/version/lens` |

Recording a fixture on first contact with an endpoint is cheap; reconstructing
one later is not, and it is the only way the derivation layer stays testable
without a router (BACKLOG #9).
