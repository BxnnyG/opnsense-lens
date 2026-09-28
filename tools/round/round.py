#!/usr/bin/env python3
"""
The router round, run by a script instead of by hand (stage 32).

    tools/round/round.py                  every box in tools/lens-deploy.sh
    tools/round/round.py router-01        one box
    tools/round/round.py --no-shots       skip the browser part

What it does, per box, and writes as report.md beside the screenshots:

  1. over SSH (one connection, reused): the installed version, the crontab,
     `configctl lens status / identity / baseline / settings / segments`,
     the store's size, what the CPU is doing, and the questions only the box
     can answer -- Unbound's qstats output, whether `ping -t` is a deadline
     for IPv6, whether the hand-run observer of August is still running
  2. over the API (needs an API key): every Lens endpoint, its status and how
     long it took, and a Prometheus scrape
  3. in a browser (needs a GUI login and Playwright): every Lens page at
     desktop width and at 390 px, with script errors, sideways scrolling and
     load time

Every check gets a verdict -- ok, look, or FAIL -- with the evidence beside it.
The human part of the round becomes reading one file and looking at pictures.

Nothing is written to the box. Output goes to ~/lens-round/<box>-<stamp>/,
outside the repository, unless --out says otherwise.

Secrets come from the environment and are never written to the report:
    LENS_API_KEY, LENS_API_SECRET      an API key of a user holding the Lens privileges
    LENS_UI_USER, LENS_UI_PASS         a GUI login, for the screenshots
    LENS_UI_URL                        when the GUI is not at https://<host>/ (one box only)
"""

import argparse
import base64
import datetime
import json
import os
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))

# every page's API, as the pages call it; timed and checked for JSON
ENDPOINTS = [
    'devices/list?hours=24',
    'devices/presence?hours=24',
    'events/list?days=7',
    'dashboard/timeline?hours=24',
    'dashboard/internet?hours=24',
    'dashboard/line?hours=24',
    'dashboard/system',
    'dashboard/heatmap',
    'dashboard/wall',
    'segments/list?hours=24',
    'dns/overview',
    'sources/report',
    'store/status',
    'settings/get',
]

# PROCESS §4: no page takes longer than a couple of seconds
SLOW_MS = 2000

# the remote part, in POSIX sh: the root shell is csh (ROADMAP operations notes)
REMOTE = r'''
section() { printf '\n@@%s@@\n' "$1"; }
# os-lens-devel is what `make package` from this repository builds (Mk/devel.mk)
section version;   pkg query '%v' os-lens os-lens-devel 2>/dev/null | head -1
section cron;      grep -n 'configctl -d lens' /var/cron/tabs/root 2>&1
section status;    configctl lens status 2>&1
section identity;  configctl lens identity 2>&1
section baseline;  configctl lens baseline 2>&1
section settings;  configctl lens settings 2>&1
section segments;  configctl lens segments 24 2>&1
section observer;  ps ax | grep -c '[l]ens-observe'
section cpu;       top -b -o cpu -d 2 12 2>&1 | tail -16
section uptime;    uptime 2>&1
section dns;       configctl unbound qstats totals 10 2>&1
section ipv6;      start=$(date +%s); ping -6 -c 3 -t 4 -q 2620:fe::fe > /dev/null 2>&1; code=$?; echo "exit=$code seconds=$(( $(date +%s) - start ))"
# what each page waits for, measured on the box itself (stage 41)
section timings
for duty in devices status 'traffic 24' 'traffic 168' baseline 'events 7' 'events 30' 'presence 24' 'segments 24'; do
    real=$( { /usr/bin/time -p configctl lens $duty > /dev/null 2>&1; } 2>&1 | awk '/^real/ {print $2}' )
    echo "$duty=$real"
done
section end
'''

# a duty the pages wait for: fine, worth a look, or the cause of "Reading..."
DUTY_OK = 2.0
DUTY_SLOW = 10.0


def boxes():
    """The boxes, read from lens-deploy.sh so there is one list, not two."""
    found = []
    with open(os.path.join(REPO, 'tools', 'lens-deploy.sh')) as handle:
        for line in handle:
            parts = line.split()
            if len(parts) >= 4 and parts[0] == 'box' and not line.startswith('\t'):
                found.append({'name': parts[1], 'host': parts[2], 'port': parts[3]})
    return found


def ssh_sections(box):
    socket = '/tmp/lens-round-%s.sock' % box['name']
    # The script travels on stdin to `sh -s`. root's login shell on OPNsense is
    # csh, and handing it the script as a quoted argument failed silently: csh
    # cannot quote across lines, so no section ran and the report read "not
    # installed, never run" on a box where Lens was installed and running.
    argv = ['ssh', '-p', box['port'], '-o', 'ConnectTimeout=8', '-o', 'ControlMaster=auto',
            '-o', 'ControlPath=' + socket, '-o', 'ControlPersist=60',
            'root@' + box['host'], 'sh -s']
    try:
        done = subprocess.run(argv, input=REMOTE, capture_output=True, text=True, timeout=600)
    except FileNotFoundError:
        return None, 'ssh is not installed on this machine'
    except (OSError, subprocess.SubprocessError) as failure:
        return None, str(failure)
    out = done.stdout
    if not out.strip():
        return None, 'no answer from root@%s:%s (key, password or network?) %s' % (
            box['host'], box['port'], done.stderr.strip()[-200:])
    sections, name = {}, None
    for line in out.splitlines():
        match = re.match(r'^@@(\w+)@@$', line)
        if match:
            name = match.group(1)
            sections[name] = []
        elif name:
            sections[name].append(line)
    if 'end' not in sections:
        # something answered, but the script did not run to its end: say so,
        # instead of reading every missing section as "not installed"
        return None, 'the remote script did not finish: %s' % (
            (done.stderr or out).strip()[-300:] or 'no output')
    return {k: '\n'.join(v).strip() for k, v in sections.items()}, None


def as_json(text):
    try:
        return json.loads(text)
    except (TypeError, ValueError):
        return None


def api(base, path, insecure):
    key, secret = os.environ.get('LENS_API_KEY'), os.environ.get('LENS_API_SECRET')
    request = urllib.request.Request(base.rstrip('/') + '/api/lens/' + path)
    request.add_header('Authorization', 'Basic ' + base64.b64encode(
        ('%s:%s' % (key, secret)).encode()).decode())
    context = ssl._create_unverified_context() if insecure else None           # noqa: S323
    started = time.time()
    try:
        with urllib.request.urlopen(request, timeout=30, context=context) as reply:
            body = reply.read().decode('utf-8', 'replace')
            return reply.status, int((time.time() - started) * 1000), body
    except urllib.error.HTTPError as failure:
        return failure.code, int((time.time() - started) * 1000), ''
    except (urllib.error.URLError, OSError) as failure:
        return None, int((time.time() - started) * 1000), str(failure)


class Report:
    def __init__(self, box):
        self.box = box
        self.rows = []
        self.notes = []

    def check(self, what, verdict, evidence):
        self.rows.append((what, verdict, evidence))

    def note(self, title, body):
        self.notes.append((title, body))

    def markdown(self, stamp, shots):
        bad = sum(1 for _, v, _ in self.rows if v == 'FAIL')
        look = sum(1 for _, v, _ in self.rows if v == 'look')
        out = ['# Router round: %s (%s)' % (self.box['name'], stamp), '',
               '%d checks · %d FAIL · %d to look at' % (len(self.rows), bad, look), '',
               '| check | verdict | evidence |', '|---|---|---|']
        for what, verdict, evidence in self.rows:
            out.append('| %s | %s | %s |' % (what, '**%s**' % verdict if verdict != 'ok' else 'ok',
                                             str(evidence).replace('|', '\\|').replace('\n', ' ')))
        if shots:
            out += ['', '## Pages', '']
            for shot in shots:
                flag = []
                if shot.get('errors'):
                    flag.append('script errors: ' + '; '.join(shot['errors'])[:300])
                if shot.get('sideways'):
                    flag.append('scrolls sideways (%dpx)' % shot['sideways'])
                out.append('### %s — %s · %d ms%s' % (shot['page'], shot['view'], shot.get('ms', 0),
                                                       (' · ' + ' · '.join(flag)) if flag else ''))
                out.append('![%s](%s)' % (shot['file'], shot['file']))
                out.append('')
        for title, body in self.notes:
            out += ['', '## ' + title, '', '```', body[:6000], '```']
        return '\n'.join(out) + '\n'


def judge_ssh(report, sec, expected_version):
    version = sec.get('version', '')
    report.check('installed version', 'ok' if version == expected_version else 'FAIL',
                 '%s (repository says %s)' % (version or 'not installed', expected_version))

    cron = sec.get('cron', '')
    has = [duty for duty in ('observe', 'harvest', 'prune') if 'lens %s' % duty in cron]
    report.check('crontab', 'ok' if {'observe', 'harvest'} <= set(has) else 'FAIL',
                 ', '.join(has) or 'no lens entry in /var/cron/tabs/root (§4.25)')

    status = as_json(sec.get('status')) or {}
    now = int(time.time())
    for duty, overdue in (('observe', 900), ('harvest', 5400)):
        run = (status.get('runs') or {}).get(duty)
        if not run:
            report.check('last %s' % duty, 'FAIL', 'has never run')
            continue
        age = now - int(run.get('at', 0))
        verdict = 'ok' if run.get('ok') and age <= overdue else 'FAIL'
        report.check('last %s' % duty, verdict, '%d s ago, took %s ms: %s'
                     % (age, run.get('took_ms'), run.get('detail')))
    if status:
        report.check('store', 'ok' if status.get('size_mb', 0) < status.get('ceiling_mb', 500) else 'FAIL',
                     '%s MB of %s MB, %s devices, %s traffic rows, schema %s'
                     % (status.get('size_mb'), status.get('ceiling_mb'), status.get('devices'),
                        status.get('traffic_rows'), status.get('schema_version')))

    identity = as_json(sec.get('identity')) or {}
    if identity:
        report.check('identity', 'ok' if identity.get('overlaps', 0) == 0 else 'look',
                     'devices %s, randomised %s, overlaps %s'
                     % (identity.get('devices'), identity.get('randomised'), identity.get('overlaps')))

    baseline = as_json(sec.get('baseline')) or {}
    if baseline:
        unusual = baseline.get('unusual') or []
        report.check('baseline', 'look' if unusual else 'ok',
                     'day %s of %s; unusual today: %s' % (
                         baseline.get('days'), baseline.get('needs_days'),
                         ', '.join('%s %.1f×' % (u.get('mac'), u.get('times') or 0) for u in unusual) or 'none'))

    observers = sec.get('observer', '0').strip()
    report.check('hand-run observer of 2026-08-29', 'ok' if observers == '0' else 'look',
                 'not running' if observers == '0' else '%s running: pkill -f lens-observe' % observers)

    dns = sec.get('dns', '')
    report.check('Unbound qstats totals', 'ok' if as_json(dns) is not None else 'look',
                 'answers JSON (the DNS view has data)' if as_json(dns) is not None
                 else (dns[:160] or 'no answer') + ' (DNS view will say Unbound records nothing)')

    ipv6 = sec.get('ipv6', '')
    seconds = re.search(r'seconds=(\d+)', ipv6)
    report.check('ping -6 -t is a deadline', 'ok' if seconds and int(seconds.group(1)) <= 6 else 'look',
                 ipv6 + ' (≤ 6 s means IPv6 probe targets can be allowed, BACKLOG #35)')

    for line in sec.get('timings', '').splitlines():
        duty, _, real = line.partition('=')
        try:
            seconds = float(real)
        except ValueError:
            report.check('duty %s' % duty, 'FAIL', 'did not answer')
            continue
        report.check('duty %s' % duty,
                     'ok' if seconds <= DUTY_OK else ('look' if seconds <= DUTY_SLOW else 'FAIL'),
                     '%.2f s on the box' % seconds)

    for name in ('cpu', 'uptime', 'segments', 'settings'):
        if sec.get(name):
            report.note(name, sec[name])


def judge_api(report, base, insecure):
    if not (os.environ.get('LENS_API_KEY') and os.environ.get('LENS_API_SECRET')):
        report.check('API', 'look', 'skipped: LENS_API_KEY / LENS_API_SECRET not set')
        return
    for path in ENDPOINTS:
        code, ms, body = api(base, path, insecure)
        ok = code == 200 and as_json(body) is not None
        report.check('GET /api/lens/' + path, 'ok' if ok and ms <= SLOW_MS else ('look' if ok else 'FAIL'),
                     '%s in %d ms%s' % (code, ms, '' if ok else ': ' + body[:120]))
    code, ms, body = api(base, 'metrics/prometheus', insecure)
    report.check('Prometheus scrape', 'ok' if code == 200 and 'lens_collector_last_run_seconds' in body else 'FAIL',
                 '%s in %d ms, %d lines' % (code, ms, body.count('\n')))


def shots(base, outdir, insecure):
    if not (os.environ.get('LENS_UI_USER') and os.environ.get('LENS_UI_PASS')):
        return None, 'skipped: LENS_UI_USER / LENS_UI_PASS not set'
    env = dict(os.environ, LENS_LOGIN='1', LENS_VIEWS='desktop,phone',
               LENS_INSECURE='1' if insecure else '0', LENS_RESULTS=os.path.join(outdir, 'pages.json'))
    try:
        subprocess.run(['node', os.path.join(REPO, 'tools', 'preview', 'shoot.js'), base, outdir],
                       env=env, timeout=900, check=False)
    except (OSError, subprocess.SubprocessError) as failure:
        return None, 'browser part failed: %s' % failure
    try:
        with open(os.path.join(outdir, 'pages.json')) as handle:
            return json.load(handle), None
    except (OSError, ValueError):
        return None, 'the browser part wrote no results (is Playwright installed? npm i -g playwright)'


def main():
    parser = argparse.ArgumentParser(description='the router round, as a script')
    parser.add_argument('box', nargs='?', help='one box from tools/lens-deploy.sh')
    parser.add_argument('--out', default=os.path.expanduser('~/lens-round'))
    parser.add_argument('--no-shots', action='store_true')
    parser.add_argument('--insecure', action='store_true',
                        help="accept the box's self-signed certificate (OPNsense's default)")
    args = parser.parse_args()

    expected = None
    with open(os.path.join(REPO, 'net-mgmt', 'lens', 'Makefile')) as handle:
        text = handle.read()
        expected = '%s_%s' % (re.search(r'PLUGIN_VERSION=\s*(\S+)', text).group(1),
                              re.search(r'PLUGIN_REVISION=\s*(\S+)', text).group(1))

    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M')
    chosen = [b for b in boxes() if not args.box or b['name'] == args.box]
    if not chosen:
        print('no such box in tools/lens-deploy.sh: %s' % args.box, file=sys.stderr)
        return 2

    failed = False
    for box in chosen:
        outdir = os.path.join(args.out, '%s-%s' % (box['name'], stamp))
        os.makedirs(outdir, exist_ok=True)
        base = (os.environ.get('LENS_UI_URL') if args.box else None) or 'https://%s' % box['host']
        report = Report(box)
        print('== %s (%s)' % (box['name'], box['host']))

        sections, error = ssh_sections(box)
        if sections is None:
            report.check('ssh', 'FAIL', error)
        else:
            judge_ssh(report, sections, expected)
        judge_api(report, base, args.insecure)

        pages = None
        if not args.no_shots:
            pages, why = shots(base, outdir, args.insecure)
            if pages is None:
                report.check('pages', 'look', why)
            else:
                for page in pages:
                    trouble = page.get('errors') or page.get('sideways')
                    slow = page.get('ms', 0) > SLOW_MS
                    report.check('page %s (%s)' % (page['page'], page['view']),
                                 'FAIL' if trouble else ('look' if slow else 'ok'),
                                 '%d ms%s%s' % (page.get('ms', 0),
                                                '; errors' if page.get('errors') else '',
                                                '; scrolls sideways' if page.get('sideways') else ''))

        path = os.path.join(outdir, 'report.md')
        with open(path, 'w') as handle:
            handle.write(report.markdown(stamp, pages))
        bad = [r for r in report.rows if r[1] == 'FAIL']
        failed = failed or bool(bad)
        print('   %d checks, %d FAIL -> %s' % (len(report.rows), len(bad), path))

    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
