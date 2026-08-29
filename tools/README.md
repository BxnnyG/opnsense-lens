# tools — hand-run, before the plugin exists

Read-only shell tools that do by hand what stages 2 to 4 will do inside the
plugin. They exist because data has a lead time that code does not (§4.14 in
[../docs/DESIGN.md](../docs/DESIGN.md)): every night the box is not observing is
a night of history the interesting stages will not have.

They are also the **executable specification** for S3 and S2. Whatever these
check, the plugin checks. If they drift apart, the plugin is wrong.

| Script | What it does | Writes |
|---|---|---|
| `lens-preflight.sh` | Reports which data sources this box has, whether they are on, and how far back their data goes | nothing — output only |
| `lens-observe.sh` | One snapshot of ARP, NDP and DHCP leases, appended to a log | `/root/lens-observations.log`, 200 MB ceiling |
| `lens-observe-summary.py` | Reads that log and answers how badly MAC randomisation fragments device identity on this network | nothing — output only |

None of them changes configuration, starts or stops a service, or touches any
subsystem. Enabling data sources is done in the web interface, deliberately, by
a human — until the wizard (stage 3) exists to do it with the costs stated.

## Use

On the OPNsense box, as root:

```sh
sh lens-preflight.sh | tee /root/lens-preflight-$(date +%Y%m%d-%H%M).txt

nohup sh -c 'while :; do sh /root/lens-observe.sh; sleep 300; done' >/dev/null 2>&1 &

# the next day
python3 lens-observe-summary.py /root/lens-observations.log

# stop observing
pkill -f lens-observe
```
