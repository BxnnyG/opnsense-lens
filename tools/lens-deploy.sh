#!/bin/sh
#
# Pull, copy to each firewall, build and install, then say what landed.
#
# Run it from anywhere:   tools/lens-deploy.sh
# One box only:           tools/lens-deploy.sh router-01
#
# It replaces four commands and two of the four password prompts: one SSH
# connection per box is opened once and reused for both the copy and the build
# (ControlMaster), so each box asks once instead of twice. An SSH key removes
# the rest.
#
# `--delete` on the copy is deliberate. Without it a file removed from the
# repository stays behind on the box and gets packaged into the next build --
# a class that no longer exists, still installed, still loaded. The remote
# directory is only ever an rsync target, so nothing else is there to lose.

set -eu

REPO=$(cd "$(dirname "$0")/.." && pwd)
REMOTE=/root/opnsense-lens

only=${1:-}
failed=""

say() { printf '\n\033[1m== %s\033[0m %s\n' "$1" "${2:-}"; }

deploy() {
	name=$1
	host=$2
	port=$3

	socket="/tmp/lens-deploy-$name.sock"
	ssh="ssh -p $port -o ControlMaster=auto -o ControlPath=$socket -o ControlPersist=120"

	say "$name" "($host)"

	if ! $ssh -o ConnectTimeout=8 "root@$host" true 2>/dev/null; then
		printf '   unreachable, skipped\n'
		failed="$failed $name"
		return
	fi

	rsync -a --delete --exclude vendor --exclude .git -e "$ssh" \
		"$REPO/" "root@$host:$REMOTE/"

	# version.sh wants a .git it does not have here; the warning is expected
	# and harmless, so only the lines that matter are kept.
	if $ssh "root@$host" "cd $REMOTE/net-mgmt/lens && make package && make upgrade" \
		2>&1 | grep -Ev 'not a git repository|version\.sh.*returned non-zero'; then
		:
	else
		failed="$failed $name"
	fi

	printf '   '
	$ssh "root@$host" 'configctl lens status' || true

	$ssh -O exit "root@$host" 2>/dev/null || true
}

box() {
	# deliberately not a loop over a list: a `while read` in a pipeline runs in
	# a subshell, and the failure it recorded would never come back out.
	[ -z "$only" ] || [ "$only" = "$1" ] || return 0
	deploy "$1" "$2" "$3"
}

echo "== git"
git -C "$REPO" pull --ff-only

#   name       host         ssh port
box router-01  10.10.10.1   22
box box-2      10.0.147.1   147

if [ -n "$failed" ]; then
	printf '\nsomething went wrong on:%s\n' "$failed" >&2
	exit 1
fi

printf '\ndone.\n'
