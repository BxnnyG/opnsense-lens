#!/bin/sh
#
# Lens on OPNsense: install, update or remove it, as root on the firewall.
#
#   fetch -o - https://raw.githubusercontent.com/BxnnyG/opnsense-lens/master/tools/install.sh | sh
#   fetch -o - https://raw.githubusercontent.com/BxnnyG/opnsense-lens/master/tools/install.sh | sh -s update
#   fetch -o - https://raw.githubusercontent.com/BxnnyG/opnsense-lens/master/tools/install.sh | sh -s uninstall
#   fetch -o - https://raw.githubusercontent.com/BxnnyG/opnsense-lens/master/tools/install.sh | sh -s uninstall --purge
#
# install    adds the Lens package feed (signed; its public key comes from this
#            repository, not from the feed) and installs os-lens. From then on
#            updates arrive with System: Firmware: Updates like any other.
# update     the same, now, without waiting for the firmware check.
# uninstall  removes the package and the feed. The store in /var/db/lens stays,
#            so a reinstall picks up where it left off; --purge deletes it.
# source     builds os-lens from the current master and installs that, for
#            when the feed is not reachable or not published yet.
# status     what is installed, from where, and when the collector last ran.
#
# Written for FreeBSD's sh; root's login shell on OPNsense is csh, which is why
# this runs as `| sh` and never as a pasted block.

set -eu

FEED=https://bxnnyg.github.io/opnsense-lens/feed
KEY_URL=https://raw.githubusercontent.com/BxnnyG/opnsense-lens/master/tools/feed/lens.pub
TARBALL=https://codeload.github.com/BxnnyG/opnsense-lens/tar.gz/refs/heads/master

# LENS_INSTALL_ROOT is for tests/python/test_install.py, never for a box
ROOT=${LENS_INSTALL_ROOT:-}
CONF=$ROOT/usr/local/etc/pkg/repos/Lens.conf
KEY=$ROOT/usr/local/etc/pkg/keys/lens.pub
STORE=$ROOT/var/db/lens
CRONTAB=$ROOT/var/cron/tabs/root

say() { printf '>>> %s\n' "$*"; }
die() { printf '!!! %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "run this as root"
[ -x "$ROOT/usr/local/sbin/opnsense-version" ] || die "this is not an OPNsense firewall"

# os-lens is the release; os-lens-devel is what `make package` from a checkout
# builds (Mk/devel.mk). Both own the same files, so only one can be installed.
installed() { pkg query '%n' os-lens os-lens-devel 2>/dev/null | head -1 || true; }

# the feed's key, fetched from the repository; fails only when it is not there
fetch_key() {
    mkdir -p "$(dirname "$KEY")" "$(dirname "$CONF")"
    fetch -q -o "$KEY.new" "$KEY_URL" 2> /dev/null && [ -s "$KEY.new" ]
}

add_feed() {
    [ -s "$KEY.new" ] || fetch_key || die "could not fetch the feed's public key from $KEY_URL"
    grep -q 'BEGIN PUBLIC KEY' "$KEY.new" || { rm -f "$KEY.new"; die "$KEY_URL is not a public key"; }
    mv "$KEY.new" "$KEY"
    cat > "$CONF" <<EOF
# Lens package feed, written by tools/install.sh
Lens: {
  url: "$FEED",
  signature_type: "pubkey",
  pubkey: "$KEY",
  enabled: yes
}
EOF
    pkg update -f -r Lens > /dev/null || die "the feed at $FEED did not answer or did not verify"
}

drop_devel() {
    if pkg info -e os-lens-devel; then
        say "removing os-lens-devel, built from a checkout (the store in $STORE stays)"
        pkg delete -y os-lens-devel > /dev/null
    fi
}

from_source() {
    work=$(mktemp -d /tmp/lens.XXXXXX)
    trap 'rm -rf "$work"' EXIT
    say "fetching master"
    fetch -q -o "$work/lens.tar.gz" "$TARBALL" || die "could not fetch $TARBALL"
    tar -xzf "$work/lens.tar.gz" -C "$work"
    dir=$(find "$work" -maxdepth 1 -type d -name 'opnsense-lens-*' | head -1)
    [ -n "$dir" ] || die "the tarball did not contain the repository"
    say "building os-lens"
    make -C "$dir/net-mgmt/lens" package PLUGIN_NO_ABI=yes PLUGIN_DEVEL= > "$work/build.log" 2>&1 \
        || { tail -20 "$work/build.log" >&2; die "the build failed"; }
    name=$(installed)
    [ -n "$name" ] && pkg delete -y "$name" > /dev/null
    pkg add "$dir"/net-mgmt/lens/work/pkg/os-lens-*.pkg
    say "installed $(pkg query '%n-%v' os-lens) from source; 'update' switches to the feed"
}

running() {
    configctl lens status 2>/dev/null | python3 -c \
        'import json, sys; print(json.load(sys.stdin)["runs"]["observe"]["at"])' 2>/dev/null || true
}

case "${1:-install}" in
install)
    if ! fetch_key; then
        # not published yet: the operator has not added the signing key
        # (docs/plans/stage-43-publish.md). Building from source still works;
        # a verification failure below never falls back to this.
        rm -f "$KEY.new"
        say "the package feed is not published yet; building from source instead"
        from_source
        exit 0
    fi
    add_feed
    drop_devel
    pkg install -y -r Lens os-lens
    say "installed $(pkg query '%n-%v' os-lens). Reporting: Lens is in the menu;"
    say "updates now come with System: Firmware: Updates."
    ;;
update)
    [ -f "$CONF" ] || add_feed
    pkg update -f -r Lens > /dev/null || die "the feed at $FEED did not answer or did not verify"
    drop_devel
    if pkg info -e os-lens; then
        pkg upgrade -y -r Lens os-lens
    else
        pkg install -y -r Lens os-lens
    fi
    say "now $(pkg query '%n-%v' os-lens)"
    ;;
uninstall|remove)
    name=$(installed)
    if [ -n "$name" ]; then
        pkg delete -y "$name"
    else
        say "Lens is not installed"
    fi
    rm -f "$CONF" "$KEY"
    if [ "${2:-}" = "--purge" ]; then
        rm -rf "$STORE"
        say "the store in $STORE is deleted"
    else
        say "the store in $STORE was kept; 'uninstall --purge' deletes it"
    fi
    ;;
source)
    from_source
    ;;
status)
    name=$(installed)
    if [ -n "$name" ]; then
        say "installed: $(pkg query '%n-%v (from %R)' "$name")"
    else
        say "not installed"
    fi
    if [ -f "$CONF" ]; then say "feed: $FEED"; else say "feed: not configured"; fi
    at=$(running)
    if [ -n "$at" ]; then
        say "collector last observed $(( $(date +%s) - at )) s ago"
    else
        say "collector: no observation recorded"
    fi
    grep -q 'configctl -d lens observe' "$CRONTAB" 2>/dev/null \
        && say "cron: scheduled" || say "cron: NOT scheduled (pluginctl -s cron restart)"
    ;;
*)
    die "usage: install.sh [install | update | uninstall [--purge] | source | status]"
    ;;
esac
