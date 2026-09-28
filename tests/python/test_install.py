"""
tools/install.sh, run by dash (the closest to FreeBSD's sh here) against stub
pkg, fetch and configctl in a scratch root. What it must never do: leave a feed
configured that it could not verify, take the store without --purge, or leave
os-lens-devel beside os-lens.
"""

import os
import shutil
import stat
import subprocess
import tempfile
import time
import unittest

REPO = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
SCRIPT = os.path.join(REPO, 'tools', 'install.sh')

PKG = r'''#!/bin/sh
state="$STUB/installed"; touch "$state"
echo "pkg $*" >> "$STUB/log"
case "$1" in
query) shift; fmt=$1; shift
       for n in "$@"; do if grep -qx "$n" "$state"; then
           case "$fmt" in '%n') echo "$n";; *) echo "$n-0.28_1";; esac; fi; done ;;
info)  grep -qx "$3" "$state" ;;
update) [ -z "${FAIL_UPDATE:-}" ] ;;
install|upgrade) echo "$5" >> "$state" ;;
delete) grep -vx "$3" "$state" > "$state.new" || true; mv "$state.new" "$state" ;;
add)   echo os-lens >> "$state" ;;
esac
'''

FETCH = r'''#!/bin/sh
echo "fetch $*" >> "$STUB/log"
out=$3
case "$4" in *.tar.gz|*/tar.gz/*) cp "$STUB/master.tar.gz" "$out"; exit 0;; esac
if [ -n "${NO_KEY:-}" ]; then exit 1; fi
if [ -n "${BAD_KEY:-}" ]; then echo '<html>404</html>' > "$out"; exit 0; fi
printf -- '-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n' > "$out"
'''

MAKE = r'''#!/bin/sh
echo "make $*" >> "$STUB/log"
mkdir -p "$2/work/pkg" && touch "$2/work/pkg/os-lens-0.28_1.pkg"
'''

CONFIGCTL = r'''#!/bin/sh
echo "{\"runs\": {\"observe\": {\"at\": $(( $(date +%s) - 90 )), \"ok\": true}}}"
'''


class InstallTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.stub = os.path.join(self.dir, 'stub')
        self.root = os.path.join(self.dir, 'root')
        os.makedirs(self.stub)
        os.makedirs(os.path.join(self.root, 'usr/local/sbin'))
        os.makedirs(os.path.join(self.root, 'var/db/lens'))
        os.makedirs(os.path.join(self.root, 'var/cron/tabs'))
        with open(os.path.join(self.root, 'var/db/lens/lens.sqlite'), 'w') as handle:
            handle.write('store')
        self.tool('usr/local/sbin/opnsense-version', '#!/bin/sh\necho 26.7\n', self.root)
        import tarfile
        tree = os.path.join(self.dir, 'opnsense-lens-master', 'net-mgmt', 'lens')
        os.makedirs(tree)
        with tarfile.open(os.path.join(self.stub, 'master.tar.gz'), 'w:gz') as tar:
            tar.add(os.path.join(self.dir, 'opnsense-lens-master'), arcname='opnsense-lens-master')
        for name, body in (('pkg', PKG), ('fetch', FETCH), ('configctl', CONFIGCTL), ('make', MAKE),
                           ('id', '#!/bin/sh\necho 0\n')):
            self.tool(name, body, self.stub)

    def tearDown(self):
        shutil.rmtree(self.dir)

    def tool(self, name, body, where):
        path = os.path.join(where, name)
        with open(path, 'w') as handle:
            handle.write(body)
        os.chmod(path, os.stat(path).st_mode | stat.S_IEXEC)

    def run_script(self, *args, **env):
        environment = dict(os.environ, PATH=self.stub + ':/usr/bin:/bin', STUB=self.stub,
                           LENS_INSTALL_ROOT=self.root, **env)
        return subprocess.run(['dash', SCRIPT] + list(args), capture_output=True, text=True,
                               env=environment, timeout=30)

    def installed(self):
        path = os.path.join(self.stub, 'installed')
        if not os.path.exists(path):
            return []
        with open(path) as handle:
            return handle.read().split()

    def conf(self):
        return os.path.join(self.root, 'usr/local/etc/pkg/repos/Lens.conf')

    def test_install_swaps_a_devel_build_for_the_release_from_a_signed_feed(self):
        with open(os.path.join(self.stub, 'installed'), 'w') as handle:
            handle.write('os-lens-devel\n')

        done = self.run_script('install')

        self.assertEqual(0, done.returncode, done.stderr)
        self.assertEqual(['os-lens'], self.installed())
        with open(self.conf()) as handle:
            conf = handle.read()
        self.assertIn('signature_type: "pubkey"', conf)
        self.assertIn('url: "https://bxnnyg.github.io/opnsense-lens/feed"', conf)
        with open(os.path.join(self.stub, 'log')) as handle:
            self.assertIn('raw.githubusercontent.com', handle.read(),
                          'the key comes from the repository, not from the feed it verifies')

    def test_a_key_that_is_not_one_leaves_no_feed_behind(self):
        done = self.run_script('install', BAD_KEY='1')

        self.assertNotEqual(0, done.returncode)
        self.assertFalse(os.path.exists(self.conf()))
        self.assertEqual([], self.installed())

    def test_a_feed_that_does_not_verify_stops_the_install(self):
        done = self.run_script('install', FAIL_UPDATE='1')

        self.assertNotEqual(0, done.returncode)
        self.assertIn('did not answer or did not verify', done.stderr)
        self.assertEqual([], self.installed())

    def test_uninstall_keeps_the_store_unless_asked(self):
        self.run_script('install')

        kept = self.run_script('uninstall')
        self.assertEqual(0, kept.returncode, kept.stderr)
        self.assertEqual([], self.installed())
        self.assertFalse(os.path.exists(self.conf()))
        self.assertTrue(os.path.exists(os.path.join(self.root, 'var/db/lens/lens.sqlite')))

        purged = self.run_script('uninstall', '--purge')
        self.assertEqual(0, purged.returncode, purged.stderr)
        self.assertFalse(os.path.exists(os.path.join(self.root, 'var/db/lens')))

    def test_update_installs_when_nothing_is_there(self):
        done = self.run_script('update')

        self.assertEqual(0, done.returncode, done.stderr)
        self.assertEqual(['os-lens'], self.installed())

    def test_status_says_how_long_ago_the_collector_looked_and_whether_cron_has_it(self):
        self.run_script('install')

        done = self.run_script('status')

        self.assertIn('installed: os-lens-0.28_1', done.stdout)
        self.assertRegex(done.stdout, r'collector last observed 9\d s ago')
        self.assertIn('cron: NOT scheduled', done.stdout)

    def test_before_the_feed_is_published_install_builds_from_source(self):
        done = self.run_script('install', NO_KEY='1')

        self.assertEqual(0, done.returncode, done.stderr)
        self.assertIn('not published yet', done.stdout)
        self.assertEqual(['os-lens'], self.installed())
        self.assertFalse(os.path.exists(self.conf()), 'no feed configured that has no key')
        with open(os.path.join(self.stub, 'log')) as handle:
            self.assertIn('PLUGIN_DEVEL=', handle.read(), 'built as the release, os-lens')

    def test_an_unknown_word_is_usage(self):
        self.assertNotEqual(0, self.run_script('frobnicate').returncode)


if __name__ == '__main__':
    unittest.main()
