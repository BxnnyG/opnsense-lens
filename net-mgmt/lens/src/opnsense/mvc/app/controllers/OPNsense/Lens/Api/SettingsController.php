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

namespace OPNsense\Lens\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Lens\LensCalls;
use OPNsense\Lens\Settings;

/**
 * Class SettingsController
 *
 * Input and output only (§4.21). The collector's lenslib/settings.py owns every
 * key and bound and refuses what is outside them; Settings says what its answer
 * means. Reachable under Services: Lens only -- a user who may read the reports
 * may not shorten their retention (§4.58).
 *
 * @package OPNsense\Lens\Api
 */
class SettingsController extends ApiControllerBase
{
    /**
     * @return array the form: every setting with its value, default and bounds
     */
    public function getAction()
    {
        $backend = new Backend();

        $read = LensCalls::many($backend, ['settings' => ['settings'], 'status' => ['status']]);

        return Settings::form(
            $read['settings'],
            $read['status'],
            SegmentsController::names()
        );
    }

    /**
     * @return array ok, or a sentence per refused field; nothing is half-applied
     */
    public function setAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }

        $fields = Settings::toStore((array)$this->request->getPost());

        /* base64url, as the label editor does (§4.30): a target name with a
           space never has to survive configd's parameter list as punctuation */
        $encoded = rtrim(strtr(base64_encode((string)json_encode($fields)), '+/', '-_'), '=');
        $reply = json_decode(trim((string)(new Backend())->configdpRun('lens configure', [$encoded])), true);

        return Settings::outcome(is_array($reply) ? $reply : []);
    }

    /**
     * Delete everything Lens has collected. S14's promise, now with a button.
     *
     * @return array
     */
    public function purgeAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST only')];
        }

        return Settings::purged((string)(new Backend())->configdRun('lens purge'));
    }

    private static function decode(Backend $backend, string $command): array
    {
        $decoded = json_decode(trim((string)$backend->configdRun($command)), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
