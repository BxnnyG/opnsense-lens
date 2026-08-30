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
 * The class under test is deliberately free of framework dependencies, so it is
 * required directly instead of booting the OPNsense autoloader.
 *
 * This directory sits outside src/ on purpose: everything under src/ goes into
 * the package and is installed into /usr/local, where opnsense-core already
 * owns mvc/tests/phpunit.xml and mvc/tests/bootstrap.php (PROCESS edge case 8).
 */

if (!function_exists('gettext')) {
    /* OPNsense supplies this; off the box the untranslated string is the answer */
    function gettext($message)
    {
        return $message;
    }
}

require_once __DIR__ . '/../net-mgmt/lens/src/opnsense/mvc/app/models/OPNsense/Lens/SourceProbe.php';
require_once __DIR__ . '/../net-mgmt/lens/src/opnsense/mvc/app/models/OPNsense/Lens/SourceReport.php';
