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

/**
 * Two duties on two schedules, and a nightly prune.
 *
 * Observation is cheap and wants to be frequent: an address that changes hands
 * between two runs leaves a gap in the window that covers it.
 *
 * The harvest exists because OPNsense keeps hourly per-device traffic for
 * twenty-four hours and then deletes it (DESIGN 1.4). Every half hour is far
 * more often than that requires -- which is the point: a run that fails, or a
 * box that is off for a morning, costs nothing, because the next run resumes
 * from the last bucket actually stored rather than from when it last ran.
 *
 * cron calls configctl from outside configd. The collector itself must never
 * call configctl, which would re-enter the daemon that is running it.
 */
function lens_cron()
{
    return [
        ['autocron' => ['/usr/local/sbin/configctl -d lens observe', '*/5']],
        ['autocron' => ['/usr/local/sbin/configctl -d lens harvest', '*/30']],
        ['autocron' => ['/usr/local/sbin/configctl -d lens prune', '17', '4']],
    ];
}
