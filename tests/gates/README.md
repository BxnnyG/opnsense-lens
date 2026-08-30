# Upstream gates, locally

`make lint` and `make style` come from `opnsense/core`'s `Mk/`, which this
repository pulls in with `.-include "${PLUGINSDIR}/../core/Mk/..."`. That include
is *silent*: with no core checkout beside this repository the targets do not
exist, and `make style` succeeds by doing nothing. That is how this plugin
shipped nine stages without the project's own gate ever running.

`bmake` is only the wrapper. Everything underneath is an ordinary command, and
this runs the subset that applies to this plugin:

```
tests/gates/run.sh            # add --fetch to re-download the tools
```

First run downloads `phpcs.phar` and core's `ruleset.xml` into `.tools/`, which
is git-ignored — a megabyte of someone else's code does not belong in a clone of
a project that does not vendor it either.

## What it runs

| Check | Upstream equivalent |
|---|---|
| style-php | `phpcs --standard=<core>/ruleset.xml src/etc/inc src/opnsense` — PSR12 plus three rules |
| lint-php | core's bundled `parallel-lint`; `php -l` per file is the same check |
| lint-xml | `xmllint --noout` on every `*.xml*` under `src` |
| lint-model | the ~18 model-XML conventions, in `lint_model.py`, driven through `xmllint` |
| lint-exec | the `git grep` for `exec(`/`shell_exec(`/`system(`/`passthru(` |
| lint-desc | `pkg-descr` exists |

It exits non-zero on errors. Style warnings and model convention findings are
printed and do not fail, which is what upstream does with them.

## What it does not run, and why

`style-python` and `lint-shell` have nothing to check — the plugin ships neither.
`lint-plist` needs `bmake` and this plugin has no `plist`. `lint-acl`,
`lint-class` and `lint-import` are three scripts out of core's `Scripts/` and
need a core checkout.

The runner prints this list on every run. A gate that quietly skips things is
the situation this directory exists to end, so it never skips quietly.

## Which branch to run it against

`master` carries the documentation and the tooling, not the stages. Run it
against `netbird/integration` for everything at once, or against a stage branch
for what one pull request will contain — the file counts differ between them,
and that is the reason.

## The tools are not the router's tools

phpcs here is 4.x from upstream's releases; FreeBSD ships whatever is in ports.
A rule added between versions is missed here and caught by the maintainer. The
version is printed on every run rather than assumed.
