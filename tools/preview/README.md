# preview — every Lens page in a browser, without a router

Built for stage 31 (DESIGN §4.59): a design change is compared before and after
here, at desktop width, in core's dark theme and at 390 px, rather than argued
about in an editor (§4.47, §4.48).

It runs Lens's **real** views, controllers and models. Only the framework around
them is replaced (`stubs.php`): configd becomes the real collector run against a
seeded store — each command built from `actions_lens.conf` the way configd
builds it — core's own commands become the fixtures in `fixtures/`, and
`config.xml` a fixture shaped like the operator's box. Nothing under
`net-mgmt/lens` changes to make it work, and nothing here is packaged.

| File | What it is |
|---|---|
| `seed.py` | thirty days of a household: five VLANs, a hypervisor's herd, phones with randomised MACs, one unusual day, one outage. Seeded, so two runs draw the same pictures |
| `stubs.php` | request, configd and config.xml, just enough for the controllers |
| `router.php` | `php -S` router: pages inside a copy of core's page chrome, `/api/lens/*` through the real controllers, static files from Lens first and core second |
| `shoot.js` | Playwright screenshots of every page, three views each, and a warning for any page that scrolls sideways |
| `fixtures/` | core's commands and config, recorded or shaped like the real thing |
| `unbound_db.py` | Unbound's query store for the preview (needs the `duckdb` module): write it beside the seeded store as `unbound.duckdb` and the DNS pages read every question, as on a box |
| `unbound_stats.py` | a stand-in for core's Unbound `stats.py`, answering in its shapes for the seeded devices |

## Use

Needs PHP ≥ 8.1, Python 3, and a checkout of `opnsense/core` on the release the
box runs (for its theme CSS, fonts and `jquery`/`opnsense.js`). A sparse
checkout of `src/opnsense/www` is enough.

```sh
git clone --depth 1 --branch stable/26.7 --filter=blob:none --sparse \
    https://github.com/opnsense/core ../opnsense-core
git -C ../opnsense-core sparse-checkout set src/opnsense/www

python3 tools/preview/seed.py /tmp/lens-preview.sqlite

LENS_PREVIEW_DB=/tmp/lens-preview.sqlite OPNSENSE_CORE=../opnsense-core \
    php -S 127.0.0.1:8088 tools/preview/router.php

# http://127.0.0.1:8088/ui/lens/dashboard            light
# http://127.0.0.1:8088/ui/lens/dashboard?theme=opnsense-dark

node tools/preview/shoot.js http://127.0.0.1:8088 /tmp/shots            # every page
node tools/preview/shoot.js http://127.0.0.1:8088 /tmp/shots device     # one
```

`LENS_PREVIEW_UNBOUND=1` in the server's environment makes the box resolve with
Unbound and record its queries, as the operator's second box does; the DNS page
and the device page's DNS card then read `unbound_stats.py`, a stand-in that
answers in the shapes core's `stats.py` prints (stage 36 §2). Without it the box
is the first one, on dnsmasq, and the DNS page says why it is empty.

`LENS_PREVIEW_CLIENT=10.10.20.11` seats the browser at a seeded device's
address (the laptop on HOME), so the pause guard (§4.83) can be seen deciding;
without it the click comes from `127.0.0.1`, a network Lens cannot tell, and
every pause is refused until a network is ticked under Settings. A pause made in
the preview lands in `firewall.json` beside the store — stand-ins for core's
Alias and Filter models in `stubs.php`, which show the page, not what core does
with the same calls.

Re-run `seed.py` before comparing screenshots taken far apart: the store's "last
observation" ages, and after fifteen minutes every page correctly says the
collector looks stale.

## What it is not

The router round. The preview has no lighttpd, no real menu, no ACL and no real
phone; it cannot tell whether a page is fast on a two-core firewall. It tells
whether a page is legible, and whether it still is in the dark and at 390 px.
