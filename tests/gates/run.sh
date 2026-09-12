#!/bin/sh
#
# The upstream gates, without bmake and without a core checkout.
#
# `make lint` and `make style` are defined in opnsense/core's Mk/ and pulled in
# by a silent `.-include`, so with no core next to this repository they do not
# fail - they do nothing. This runs the subset of those checks that applies to
# this plugin, and says out loud which ones it cannot run.
#
# Unlike the plugins collection, this repository holds one plugin in a
# subdirectory and its tests at the top, so PLUGIN is not the repository root.
#
# Usage: tests/gates/run.sh [--fetch]

set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "${HERE}/../.." && pwd)
PLUGIN="${REPO}/net-mgmt/lens"
TOOLS="${HERE}/.tools"

PHPCS="${TOOLS}/phpcs.phar"
RULESET="${TOOLS}/ruleset.xml"

PHPCS_URL="https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/latest/download/phpcs.phar"
RULESET_URL="https://raw.githubusercontent.com/opnsense/core/master/ruleset.xml"

errors=0
warnings=0

say() { printf '%s\n' "$*"; }
head_() { printf '\n=== %s\n' "$*"; }

fetch_tools() {
	mkdir -p "${TOOLS}"
	say ">>> fetching phpcs and the core ruleset into tests/gates/.tools"
	curl -sSL -o "${PHPCS}" "${PHPCS_URL}"
	curl -sSL -o "${RULESET}" "${RULESET_URL}"
}

[ "${1:-}" = "--fetch" ] && fetch_tools

if [ ! -f "${PHPCS}" ] || [ ! -f "${RULESET}" ]; then
	fetch_tools
fi

# ---------------------------------------------------------------- style-php
# core: phpcs --standard=<core>/ruleset.xml src/etc/inc src/opnsense
head_ "style-php ($(php "${PHPCS}" --version))"
style_out="${TOOLS}/.style.out"
: > "${style_out}"
for dir in "${PLUGIN}/src/etc/inc" "${PLUGIN}/src/opnsense"; do
	[ -d "${dir}" ] || continue
	php "${PHPCS}" --standard="${RULESET}" "${dir}" >> "${style_out}" 2>&1 || true
done
se=$(grep -c '| ERROR' "${style_out}" || true)
sw=$(grep -c '| WARNING' "${style_out}" || true)
[ "${se}" -gt 0 ] || [ "${sw}" -gt 0 ] && cat "${style_out}"
say "style errors: ${se}, warnings: ${sw}"
errors=$((errors + se))
warnings=$((warnings + sw))
rm -f "${style_out}"

# ---------------------------------------------------------------- lint-php
# core runs its bundled parallel-lint; php -l is the same check, one file at a time
head_ "lint-php"
pe=0
for f in $(find "${PLUGIN}/src" -name '*.php' -type f); do
	php -l "${f}" > /dev/null 2>&1 || { php -l "${f}" || true; pe=$((pe + 1)); }
done
say "php syntax errors: ${pe}"
errors=$((errors + pe))

# ---------------------------------------------------------------- lint-xml
head_ "lint-xml"
xe=0
for f in $(find "${PLUGIN}/src" -name '*.xml*' -type f); do
	xmllint --noout "${f}" || xe=$((xe + 1))
done
say "xml files checked: $(find "${PLUGIN}/src" -name '*.xml*' -type f | wc -l | tr -d ' '), errors: ${xe}"
errors=$((errors + xe))

# ---------------------------------------------------------------- lint-model
head_ "lint-model"
if python3 "${HERE}/lint_model.py" "${PLUGIN}"; then
	:
else
	errors=$((errors + 1))
fi

# ---------------------------------------------------------------- lint-exec
# core greps for shell-outs that bypass OPNsense\Core\Shell
head_ "lint-exec"
hits=$(git -C "${PLUGIN}" grep -n -e '[^li][^w>:]exec(' -e '^exec(' -e 'shell_exec(' \
	-e '[^f]passthru(' -e '^passthru(' -e '[^._a-z]system(' -e '^system(' \
	-- 'src' ':!*.js' ':!*.py' 2>/dev/null || true)
if [ -n "${hits}" ]; then
	say "${hits}"
	say "shell-out call sites: $(printf '%s\n' "${hits}" | wc -l | tr -d ' ') (review, not an error)"
else
	say "no direct shell-out call sites"
fi

# ------------------------------------------------------------- style-python
# core: pycodestyle --max-line-length=120 over the shipped python
head_ "style-python"
pyfiles=$(find "${PLUGIN}/src" -name '*.py' -type f | sort)
if [ -z "${pyfiles}" ]; then
	say "the plugin ships no Python"
else
	pe=0
	for f in ${pyfiles}; do
		python3 -m py_compile "${f}" 2>&1 || { pe=$((pe + 1)); }
	done
	say "python syntax errors: ${pe}"
	errors=$((errors + pe))

	long=$(awk 'length > 120 {print FILENAME ":" FNR ": " length " chars"}' ${pyfiles} || true)
	if [ -n "${long}" ]; then
		say "${long}"
		say "lines over 120 characters: $(printf '%s\n' "${long}" | wc -l | tr -d ' ')"
		errors=$((errors + 1))
	else
		say "no lines over 120 characters"
	fi

	if command -v pycodestyle > /dev/null 2>&1; then
		pycodestyle --max-line-length=120 ${pyfiles} || errors=$((errors + 1))
		say "pycodestyle clean"
	else
		say "pycodestyle not installed here - only syntax and line length checked"
	fi
	find "${PLUGIN}/src" -name '__pycache__' -type d -exec rm -rf {} + 2>/dev/null || true
fi

# ---------------------------------------------------------------- lint-desc
head_ "lint-desc"
if [ -f "${PLUGIN}/pkg-descr" ]; then
	say "pkg-descr present"
else
	say "MISSING pkg-descr"
	errors=$((errors + 1))
fi

# ---------------------------------------------------------------- not run
# ---------------------------------------------------------------- lint-acl
# core: Scripts/dashboard-acl.sh, which needs a core checkout. This is the part
# of it that applies here, and it exists because the ACL was written for two
# pages and not extended when a third controller arrived.
head_ "lint-acl"
if python3 "${HERE}/lint_acl.py" "${PLUGIN}"; then
	:
else
	errors=$((errors + 1))
fi

# ---------------------------------------------------------------- lint-js
# dashboard widgets are ES modules; node reads a .js as CommonJS, where `export`
# is a syntax error, so the check runs over a copy named .mjs
head_ "lint-js"
je=0
jn=0
if command -v node > /dev/null 2>&1; then
	for f in $(find "${PLUGIN}/src/opnsense/www/js" -name '*.js' -type f 2>/dev/null); do
		jn=$((jn + 1))
		cp "${f}" "${TOOLS}/.check.mjs"
		node --check "${TOOLS}/.check.mjs" || je=$((je + 1))
	done
	rm -f "${TOOLS}/.check.mjs"
	say "widget scripts checked: ${jn}, errors: ${je}"
	errors=$((errors + je))
else
	say "node is not installed here - widget scripts unchecked"
fi

# ---------------------------------------------------------------- lint-shell
# the two package scripts that write the crontab; they run as root at install
head_ "lint-shell"
she=0
for f in $(find "${PLUGIN}" -maxdepth 1 -name '+*.p*' -type f); do
	sh -n "${f}" || she=$((she + 1))
done
say "package scripts checked: $(find "${PLUGIN}" -maxdepth 1 -name '+*.p*' -type f | wc -l | tr -d ' '), errors: ${she}"
errors=$((errors + she))

head_ "not run here, and why"
say "lint-plist    - needs bmake; this plugin ships no plist"
say "lint-class    - needs opnsense/core's Scripts/class-filename.sh"
say "lint-import   - needs opnsense/core's Scripts/class-import.sh"

head_ "total"
say "errors: ${errors}, warnings: ${warnings}"
[ "${errors}" -eq 0 ] || exit 1
