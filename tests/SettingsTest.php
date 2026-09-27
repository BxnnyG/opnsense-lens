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

use OPNsense\Lens\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The settings page (§4.58): a form built from the collector's answer, and a
 * sentence for every code the collector can refuse with.
 */
class SettingsTest extends TestCase
{
    /** `lens settings` as the collector prints it on a fresh store */
    private function raw(array $values = []): array
    {
        $defaults = [
            'retention_days' => 365, 'disk_ceiling_mb' => 500, 'observation_gap' => 900,
            'probe_enabled' => true, 'gateway_samples' => true,
            'probe_targets' => [
                ['name' => 'Quad9', 'address' => '9.9.9.9'],
                ['name' => 'Cloudflare', 'address' => '1.1.1.1'],
                ['name' => 'Google', 'address' => '8.8.8.8'],
            ],
            'baseline_days' => 21, 'baseline_factor' => 4.0, 'baseline_floor_mb' => 100,
        ];

        return [
            'values' => array_merge($defaults, $values),
            'defaults' => $defaults,
            'bounds' => [
                'retention_days' => [7, 3650], 'disk_ceiling_mb' => [50, 20000],
                'observation_gap' => [600, 3600], 'probe_targets' => [1, 3],
                'baseline_days' => [7, 90], 'baseline_factor' => [1.5, 20.0],
                'baseline_floor_mb' => [1, 100000],
            ],
        ];
    }

    private function fields(array $form): array
    {
        $fields = [];
        foreach ($form['blocks'] as $block) {
            foreach ($block['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }
        return $fields;
    }

    public function testEveryKeyThePageMaySendHasAField()
    {
        $fields = $this->fields(Settings::form($this->raw()));

        $this->assertSame(Settings::KEYS, array_values(array_intersect(Settings::KEYS, array_keys($fields))));
        $this->assertCount(count(Settings::KEYS), $fields);
    }

    public function testAFieldCarriesItsValueItsDefaultAndItsBounds()
    {
        $fields = $this->fields(Settings::form($this->raw(['retention_days' => 90])));

        $this->assertSame(90, $fields['retention_days']['value']);
        $this->assertSame(365, $fields['retention_days']['default']);
        $this->assertSame(7, $fields['retention_days']['min']);
        $this->assertSame(3650, $fields['retention_days']['max']);
    }

    public function testTheGapIsStoredInSecondsAndShownInMinutes()
    {
        $fields = $this->fields(Settings::form($this->raw(['observation_gap' => 1200])));

        $this->assertEquals(20, $fields['observation_gap']['value']);
        $this->assertEquals(15, $fields['observation_gap']['default']);
        $this->assertEquals(10, $fields['observation_gap']['min']);
        $this->assertEquals(60, $fields['observation_gap']['max']);
    }

    public function testMinutesGoBackAsSeconds()
    {
        $this->assertSame(['observation_gap' => 1200], Settings::toStore(['observation_gap' => '20']));
    }

    public function testOnlyKnownKeysAreForwarded()
    {
        $fields = Settings::toStore(['retention_days' => '30', 'schema_version' => '1', 'purge' => '1']);

        $this->assertSame(['retention_days' => '30'], $fields);
    }

    public function testTextIsForwardedAsTextForTheCollectorToRefuse()
    {
        /* the browser enforces nothing; the collector owns the bounds */
        $this->assertSame(['observation_gap' => 'soon'], Settings::toStore(['observation_gap' => 'soon']));
    }

    public function testTheTargetsFieldOffersAsManyRowsAsTheCollectorAllows()
    {
        $fields = $this->fields(Settings::form($this->raw()));

        $this->assertSame(3, $fields['probe_targets']['rows']);
        $this->assertSame('Quad9', $fields['probe_targets']['value'][0]['name']);
    }

    public function testNoAnswerFromTheCollectorIsSaidNotDrawnAsAnEmptyForm()
    {
        $form = Settings::form([]);

        $this->assertFalse($form['available']);
        $this->assertSame([], $form['blocks']);
    }

    public function testASavedReplyIsOk()
    {
        $outcome = Settings::outcome(['status' => 'saved', 'saved' => ['retention_days']]);

        $this->assertSame('ok', $outcome['status']);
    }

    public function testARefusalNamesTheBoundInTheUnitThePageShows()
    {
        $outcome = Settings::outcome([
            'status' => 'invalid',
            'errors' => ['observation_gap' => ['too_small', null]],
            'bounds' => ['observation_gap' => [600, 3600]],
        ]);

        $this->assertSame('invalid', $outcome['status']);
        $this->assertSame('The smallest this can be is 10.', $outcome['errors']['observation_gap']);
    }

    public function testATargetRefusalNamesItsRow()
    {
        $outcome = Settings::outcome([
            'status' => 'invalid',
            'errors' => ['probe_targets' => ['ipv6', 2]],
            'bounds' => [],
        ]);

        $this->assertStringStartsWith('Row 2: IPv6 targets are not offered yet', $outcome['errors']['probe_targets']);
    }

    public function testEveryCodeTheCollectorKnowsHasItsOwnSentence()
    {
        $codes = ['too_small', 'too_large', 'not_a_number', 'not_whole', 'not_a_flag', 'too_few',
                  'too_many', 'bad_name', 'bad_address', 'ipv6', 'not_public', 'duplicate', 'bad_row',
                  'unknown', 'not_an_object'];

        foreach ($codes as $code) {
            $outcome = Settings::outcome(['status' => 'invalid', 'errors' => ['x' => [$code, 1]], 'bounds' => []]);
            $this->assertStringNotContainsString('Refused (', $outcome['errors']['x'], $code);
        }
    }

    public function testACodeThisFileHasNotLearnedStillReachesThePage()
    {
        $outcome = Settings::outcome(['status' => 'invalid', 'errors' => ['x' => ['brand_new', null]]]);

        $this->assertSame('Refused (brand_new).', $outcome['errors']['x']);
    }

    public function testAnythingElseIsAFailureAndSaysNothingChanged()
    {
        $outcome = Settings::outcome([]);

        $this->assertSame('failed', $outcome['status']);
        $this->assertStringContainsString('Nothing was changed', $outcome['message']);
    }

    public function testPurgeIsOnlyOkWhenConfigdSaysOk()
    {
        $this->assertSame('ok', Settings::purged("OK\n")['status']);
        $this->assertSame('failed', Settings::purged('Error (1)')['status']);
        $this->assertSame('failed', Settings::purged('')['status']);
    }

    public function testThePurgeDialogueSaysWhatGoesAndWhatStays()
    {
        $summary = Settings::purgeSummary([
            'devices' => 13, 'observations' => 420, 'traffic_rows' => 7754, 'size_mb' => 4.2,
        ]);

        $this->assertStringStartsWith('13 devices', $summary['removes'][0]);
        /* traffic_rows counts rows, one per address, interface, direction and hour */
        $this->assertContains('7754 rows of hourly traffic', $summary['removes']);
        $this->assertContains('these settings', $summary['keeps']);
        $this->assertNotEmpty($summary['after']);
    }
}
