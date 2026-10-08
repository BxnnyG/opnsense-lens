#!/usr/local/bin/php
<?php

/*
 * Copyright (C) 2026 Benny <claude@bxnny.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/*
 * The two moments a pause changes without a click (§4.74): its time has
 * come (started by the observe duty, only when one is due), and Lens is being
 * removed (+PRE_DEINSTALL, never on an upgrade). Everything goes through the
 * same PauseRule the page uses, so the configuration history says the same
 * thing either way.
 *
 * usage: pause.php expire | uninstall
 */

require_once('script/load_phalcon.php');

use OPNsense\Core\Backend;
use OPNsense\Lens\Pause;
use OPNsense\Lens\PauseRule;

$backend = new Backend();
$pauses = json_decode(trim((string)$backend->configdRun('lens pauses')), true);
$open = is_array($pauses) ? (array)($pauses['open'] ?? []) : [];

switch ($argv[1] ?? '') {
    case 'expire':
        $due = Pause::due($open, time());
        if ($due === []) {
            exit(0);
        }
        $macs = [];
        foreach ($open as $pause) {
            if (in_array((string)$pause['mac'], $due, true)) {
                $macs = array_merge($macs, (array)$pause['macs']);
            }
        }
        $changed = PauseRule::change([], $macs, gettext('Lens: a pause ended on time'));
        if (!$changed['ok']) {
            fwrite(STDERR, (string)$changed['message'] . "\n");
            exit(1);
        }
        foreach ($due as $key) {
            $backend->configdpRun('lens pause.end', [$key, 'expired']);
        }
        echo count($due) . " ended\n";
        exit(0);

    case 'uninstall':
        PauseRule::remove();
        foreach ($open as $pause) {
            $backend->configdpRun('lens pause.end', [(string)$pause['mac'], 'uninstall']);
        }
        exit(0);

    default:
        fwrite(STDERR, "usage: pause.php expire | uninstall\n");
        exit(2);
}
